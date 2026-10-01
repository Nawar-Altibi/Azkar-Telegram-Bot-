<?php

namespace Tests\Feature;

use App\Enums\AdhkarMode;
use App\Enums\ReminderLogStatus;
use App\Enums\ReminderType;
use App\Jobs\SendAdhkarReminderJob;
use App\Models\AdhkarItem;
use App\Models\ReminderLog;
use App\Models\User;
use App\Services\ContentSyncService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReminderDeliveryAndVersioningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(ContentSyncService::class)->sync();
    }

    public function test_send_job_stores_content_version_snapshot_that_survives_later_sync(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 77]], 200),
        ]);
        $user = User::factory()->create([
            'telegram_id' => 2002,
            'chat_id' => 2002,
            'mode' => AdhkarMode::Short,
        ]);
        $log = ReminderLog::factory()->create([
            'user_id' => $user->id,
            'reminder_type' => ReminderType::Morning,
            'collection_key' => 'morning_short',
            'status' => ReminderLogStatus::Pending,
            'scheduled_local_date' => now()->timezone('Asia/Damascus')->toDateString(),
        ]);

        SendAdhkarReminderJob::dispatchSync($log->id);
        $log->refresh();

        $this->assertSame(ReminderLogStatus::Sent, $log->status);
        $this->assertNull($log->content_snapshot_hash);
        $this->assertNotNull($log->last_dispatch_attempt_at);
        Http::assertSent(function ($request) use ($log) {
            $text = (string) ($request['text'] ?? '');
            $markup = (string) ($request['reply_markup'] ?? '');

            return str_contains($text, 'حان وقت أذكار الصباح')
                && str_contains($text, 'تقريبًا 5 دقائق')
                && str_contains($markup, 'تعديل الوقت')
                && str_contains($markup, 'reminder:start:'.$log->id.':s')
                && str_contains($markup, 'reminder:start:'.$log->id.':a');
        });

        app(\App\Services\Telegram\TelegramUpdateProcessor::class)->handle(
            $this->telegramCallbackPayload('reminder:start:'.$log->id, 80, 2002)
        );
        $log->refresh();

        $this->assertNotNull($log->reading_started_at);
        $this->assertNotEmpty($log->content_version_map);
        $this->assertNotEmpty($log->content_snapshot_hash);
        $originalMap = $log->content_version_map;

        $item = AdhkarItem::query()->where('key', 'morning.01')->first();
        $mapped = collect($log->content_version_map)->firstWhere('key', 'morning.01');
        $this->assertNotNull($mapped);

        $item->update(['arabic_text' => 'نص مؤقت للتجربة', 'source_hash' => 'changed']);
        app(ContentSyncService::class)->sync();

        $this->assertSame($originalMap, $log->fresh()->content_version_map);
        $this->assertNotSame($mapped['content_version'], $item->fresh()->content_version);
    }

    public function test_blocked_user_disables_reminders(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => false,
                'description' => 'Forbidden: bot was blocked by the user',
            ], 403),
        ]);

        $user = User::factory()->create(['chat_id' => 3003, 'telegram_id' => 3003]);
        $log = ReminderLog::factory()->create([
            'user_id' => $user->id,
            'collection_key' => 'morning_short',
            'status' => ReminderLogStatus::Pending,
        ]);

        SendAdhkarReminderJob::dispatchSync($log->id);

        $this->assertSame(ReminderLogStatus::Failed, $log->fresh()->status);
        $this->assertFalse($user->fresh()->reminders_enabled);
    }
}
