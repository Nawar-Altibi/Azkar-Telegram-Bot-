<?php

namespace Tests\Feature;

use App\Enums\AdhkarCategory;
use App\Enums\ReminderLogStatus;
use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\AdhkarCollection;
use App\Models\AdhkarItem;
use App\Models\ReminderLog;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\ContentSyncService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdditionalAndCompletionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);
        app(ContentSyncService::class)->sync();
    }

    public function test_additional_items_are_not_in_morning_collections(): void
    {
        $morning = AdhkarCollection::query()->where('key', 'morning_full')->first();
        $this->assertFalse(
            $morning->items()->where('category', AdhkarCategory::Additional)->exists()
        );
        $this->assertTrue(AdhkarItem::query()->where('key', 'additional.01')->exists());
    }

    public function test_completion_callback_is_idempotent(): void
    {
        $user = User::factory()->create(['telegram_id' => 1001, 'chat_id' => 1001]);
        $log = ReminderLog::factory()->create([
            'user_id' => $user->id,
            'status' => ReminderLogStatus::Sent,
            'sent_at' => now(),
        ]);

        $this->process($this->telegramCallbackPayload('reminder:done:'.$log->id, 40));
        $this->assertNotNull($log->fresh()->completed_at);

        $this->process($this->telegramCallbackPayload('reminder:done:'.$log->id, 41));
        $this->assertSame(ReminderLogStatus::Completed, $log->fresh()->status);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function process(array $payload): void
    {
        $this->postWebhook($payload)->assertOk();
        $record = TelegramUpdate::query()->where('update_id', $payload['update_id'])->first();
        ProcessTelegramUpdateJob::dispatchSync($record->id);
    }
}
