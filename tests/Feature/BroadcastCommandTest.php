<?php

namespace Tests\Feature;

use App\Models\Broadcast;
use App\Models\BroadcastDelivery;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Jobs\ProcessTelegramUpdateJob;
use App\Services\Telegram\TelegramUpdateProcessor;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BroadcastCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'telegram.admin_ids' => ['1001'],
            'telegram.broadcast_batch_size' => 10,
            'telegram.broadcast_delay_microseconds' => 0,
        ]);

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);
    }

    public function test_only_configured_admin_can_broadcast(): void
    {
        User::factory()->create(['telegram_id' => 2002, 'chat_id' => 2002]);

        $this->process($this->telegramMessagePayload('/broadcast رسالة غير مصرح بها', 51, 2002));

        $this->assertDatabaseCount('broadcasts', 0);
        Http::assertSentCount(1); // The regular unknown-command response, not a broadcast.

        config(['telegram.admin_ids' => []]);
        $this->process($this->telegramMessagePayload('/broadcast ما زال غير مسموح', 52, 1001));

        $this->assertDatabaseCount('broadcasts', 0);
    }

    public function test_admin_can_send_multiline_text_to_all_users_including_opted_out_reminders(): void
    {
        User::factory()->create(['telegram_id' => 1001, 'chat_id' => 1001, 'reminders_enabled' => false]);
        User::factory()->create(['telegram_id' => 2002, 'chat_id' => 2002, 'reminders_enabled' => false]);

        $this->process($this->telegramMessagePayload("/broadcast السلام عليكم\nالبوت متوقف <مؤقتاً> & نعمل على إصلاحه", 61));

        $broadcast = Broadcast::query()->sole();
        $this->assertSame(2, $broadcast->target_count);
        $this->assertSame(0, $broadcast->sent_count);
        Http::assertSentCount(1); // Only the acknowledgement before cron.

        $this->cron();
        $this->cron(); // Completes the batch without repeating any message.

        $this->assertSame('completed', $broadcast->fresh()->status);
        $this->assertSame(2, $broadcast->fresh()->sent_count);
        $this->assertSame(0, $broadcast->fresh()->failed_count);
        $this->assertDatabaseCount('broadcast_deliveries', 2);
        $this->assertSame(2, BroadcastDelivery::query()->where('status', 'sent')->count());

        $broadcastRequests = collect(Http::recorded())->map(fn ($pair) => $pair[0])->filter(
            fn ($request) => str_contains((string) ($request['text'] ?? ''), 'البوت متوقف')
        );
        $this->assertCount(2, $broadcastRequests);
        $this->assertEqualsCanonicalizing([1001, 2002], $broadcastRequests->map(
            fn ($request) => (int) $request['chat_id']
        )->all());
        foreach ($broadcastRequests as $request) {
            $this->assertStringContainsString('&lt;مؤقتاً&gt; &amp;', (string) $request['text']);
        }
    }

    public function test_replayed_telegram_update_does_not_create_or_send_a_second_broadcast(): void
    {
        User::factory()->create(['telegram_id' => 1001, 'chat_id' => 1001]);

        $payload = $this->telegramMessagePayload('/broadcast رسالة واحدة', 71);
        app(TelegramUpdateProcessor::class)->handle($payload);
        app(TelegramUpdateProcessor::class)->handle($payload);

        $this->assertDatabaseCount('broadcasts', 1);
        $this->cron();
        $this->cron();

        $this->assertSame(1, BroadcastDelivery::query()->where('status', 'sent')->count());
        $this->assertSame(1, Broadcast::query()->sole()->sent_count);
    }

    public function test_command_with_no_message_does_not_schedule_anything(): void
    {
        $this->process($this->telegramMessagePayload('/broadcast', 81));

        $this->assertDatabaseCount('broadcasts', 0);
        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'اكتب الرسالة'));
    }

    public function test_one_blocked_user_does_not_stop_delivery_to_others(): void
    {
        User::factory()->create(['telegram_id' => 1001, 'chat_id' => 1001]);
        User::factory()->create(['telegram_id' => 2002, 'chat_id' => 2002]);
        Http::fake(function ($request) {
            if ((int) ($request['chat_id'] ?? 0) === 2002) {
                return Http::response(['ok' => false, 'description' => 'Forbidden: bot was blocked by the user'], 403);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200);
        });

        $this->process($this->telegramMessagePayload('/broadcast تنويه', 91));
        $this->cron();
        $this->cron();

        $broadcast = Broadcast::query()->sole();
        $this->assertSame(1, $broadcast->sent_count);
        $this->assertSame(1, $broadcast->failed_count);
        $this->assertSame('completed', $broadcast->status);
        $this->assertSame(1, BroadcastDelivery::query()->where('status', 'failed')->count());
        $this->assertDatabaseCount('broadcast_deliveries', 2);
    }

    public function test_batch_limit_defers_remaining_recipients_to_next_tick(): void
    {
        config(['telegram.broadcast_batch_size' => 1]);
        User::factory()->create(['telegram_id' => 1001, 'chat_id' => 1001]);
        User::factory()->create(['telegram_id' => 2002, 'chat_id' => 2002]);

        $this->process($this->telegramMessagePayload('/broadcast على دفعات', 101));
        $this->cron();
        $this->assertSame(1, Broadcast::query()->sole()->sent_count);

        $this->cron();
        $this->cron();
        $this->assertSame('completed', Broadcast::query()->sole()->status);
        $this->assertSame(2, Broadcast::query()->sole()->sent_count);
        $this->assertDatabaseCount('broadcast_deliveries', 2);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function process(array $payload): void
    {
        $this->postWebhook($payload)->assertOk();
        $record = TelegramUpdate::query()->where('update_id', $payload['update_id'])->firstOrFail();
        ProcessTelegramUpdateJob::dispatchSync($record->id);
    }

    private function cron(): void
    {
        config(['queue.default' => 'sync']);
        $this->postJson('/internal/cron', [], [
            'Authorization' => 'Bearer test-cron-secret',
        ])->assertOk();
    }
}
