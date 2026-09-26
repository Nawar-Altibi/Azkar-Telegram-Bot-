<?php

namespace Tests\Feature;

use App\Enums\AdhkarMode;
use App\Enums\ReminderLogStatus;
use App\Enums\ReminderType;
use App\Jobs\SendAdhkarReminderJob;
use App\Models\ReminderLog;
use App\Models\User;
use App\Services\AdhkarContentService;
use App\Services\ContentSyncService;
use App\Services\Telegram\TelegramUpdateProcessor;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ReminderReadingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 5]], 200),
        ]);
        app(ContentSyncService::class)->sync();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        ReminderLog::flushEventListeners();
        parent::tearDown();
    }

    public function test_start_uses_scheduled_collection_after_mode_change(): void
    {
        $user = $this->user();
        $log = $this->sentLog($user, 'morning_short');
        $user->update(['mode' => AdhkarMode::Full]);

        $this->start($log);

        $log->refresh();
        $this->assertNotNull($log->reading_started_at);
        $keys = array_column($log->content_version_map, 'key');
        $this->assertContains('morning.01', $keys);
        $this->assertNotContains('morning.10', $keys);
        Http::assertNotSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'اللَّهُمَّ بِكَ أَصْبَحْنَا'));
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'من '));
    }

    public function test_second_start_does_not_send_the_reading_again(): void
    {
        $user = $this->user();
        $log = $this->sentLog($user, 'morning_short');

        $this->start($log, 10);
        $first = $this->readingMessages();
        $this->start($log, 11);
        $second = $this->readingMessages();

        $this->assertNotEmpty($first);
        $this->assertSame($first, $second);
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'بدأت هذه الأذكار'));
    }

    public function test_claim_rollback_does_not_send_chunks(): void
    {
        $user = $this->user();
        $log = $this->sentLog($user, 'morning_short');
        ReminderLog::updating(function (ReminderLog $row) {
            if ($row->isDirty('reading_started_at')) {
                throw new RuntimeException('claim rollback');
            }
        });

        $this->start($log);

        $this->assertNull($log->fresh()->reading_started_at);
        $this->assertNull($log->fresh()->content_snapshot_hash);
        $this->assertSame([], $this->readingMessages());
    }

    public function test_expired_start_does_not_send_or_create_a_log(): void
    {
        $user = $this->user();
        $log = $this->sentLog($user, 'morning_short');
        $log->update(['scheduled_local_date' => now()->timezone('Asia/Damascus')->subDay()->toDateString()]);
        $before = ReminderLog::query()->count();

        $this->start($log);

        $this->assertSame($before, ReminderLog::query()->count());
        $this->assertNull($log->fresh()->reading_started_at);
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'هذا التذكير انتهى'));
        Http::assertSent(fn ($request) => str_contains((string) ($request['reply_markup'] ?? ''), 'today:menu'));
        $this->assertSame([], $this->readingMessages());
    }

    public function test_timezone_change_expires_start_when_the_local_date_no_longer_matches(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 22:00:00', 'UTC'));
        $user = $this->user();
        $log = $this->sentLog($user, 'morning_short');
        $log->update(['scheduled_local_date' => '2026-06-16']);
        $user->update(['timezone' => 'America/New_York', 'timezone_label' => 'أمريكا · نيويورك']);

        $this->start($log);

        $this->assertNull($log->fresh()->reading_started_at);
        $this->assertSame('morning_short', $log->fresh()->collection_key);
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'هذا التذكير انتهى'));
    }

    public function test_start_from_another_user_does_not_write(): void
    {
        $owner = $this->user(2002);
        $log = $this->sentLog($owner, 'morning_short');
        User::factory()->create(['telegram_id' => 1001, 'chat_id' => 1001]);

        app(TelegramUpdateProcessor::class)->handle(
            $this->telegramCallbackPayload('reminder:start:'.$log->id, 30, 1001)
        );

        $this->assertNull($log->fresh()->reading_started_at);
    }

    public function test_done_is_idempotent_and_rejects_invalid_cases(): void
    {
        $user = $this->user();
        $log = $this->sentLog($user, 'morning_short');
        $log->update([
            'reading_started_at' => now(),
            'content_snapshot_hash' => str_repeat('c', 64),
            'content_version_map' => [],
        ]);

        $this->done($log, 40);
        $this->assertNotNull($log->fresh()->completed_at);
        $this->done($log, 41);
        $this->assertSame(ReminderLogStatus::Completed, $log->fresh()->status);

        $pending = ReminderLog::factory()->create([
            'user_id' => $user->id,
            'reminder_type' => ReminderType::Evening,
            'collection_key' => 'evening_short',
            'status' => ReminderLogStatus::Sent,
            'sent_at' => now(),
            'scheduled_local_date' => now()->timezone('Asia/Damascus')->toDateString(),
        ]);
        $this->done($pending, 42);
        $this->assertNull($pending->fresh()->completed_at);

        $expired = ReminderLog::factory()->create([
            'user_id' => $user->id,
            'reminder_type' => ReminderType::Morning,
            'collection_key' => 'morning_short',
            'status' => ReminderLogStatus::Sent,
            'sent_at' => now(),
            'reading_started_at' => now(),
            'content_snapshot_hash' => str_repeat('d', 64),
            'scheduled_local_date' => now()->timezone('Asia/Damascus')->subDay()->toDateString(),
        ]);
        $this->done($expired, 43);
        $this->assertNull($expired->fresh()->completed_at);
    }

    public function test_done_from_another_user_does_not_complete(): void
    {
        $owner = $this->user(2002);
        $log = $this->sentLog($owner, 'morning_short');
        $log->update([
            'reading_started_at' => now(),
            'content_snapshot_hash' => str_repeat('e', 64),
        ]);
        User::factory()->create(['telegram_id' => 1001, 'chat_id' => 1001]);

        app(TelegramUpdateProcessor::class)->handle(
            $this->telegramCallbackPayload('reminder:done:'.$log->id, 44, 1001)
        );

        $this->assertNull($log->fresh()->completed_at);
    }

    public function test_partial_chunk_failure_keeps_the_claim_and_hides_the_technical_error(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 1]])
                ->push(['ok' => false, 'description' => 'Bad Request: secret-api-error'], 400)
                ->push(['ok' => true, 'result' => ['message_id' => 4]]),
        ]);
        $user = $this->user();
        $log = $this->sentLog($user, 'morning_short');

        $this->start($log, 50);

        $log->refresh();
        $this->assertNotNull($log->reading_started_at);
        $this->assertNotNull($log->content_snapshot_hash);
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'حدث خطأ أثناء إرسال الأذكار'));
        Http::assertNotSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'secret-api-error'));

        $before = $this->readingMessages();
        $this->start($log, 51);
        $this->assertSame($before, $this->readingMessages());
    }

    public function test_nudge_database_failure_is_not_resent(): void
    {
        $user = $this->user();
        $log = ReminderLog::factory()->create([
            'user_id' => $user->id,
            'collection_key' => 'morning_short',
            'status' => ReminderLogStatus::Pending,
            'scheduled_local_date' => now()->timezone('Asia/Damascus')->toDateString(),
        ]);
        ReminderLog::updating(function (ReminderLog $row) {
            if ($row->isDirty('status') && $row->status === ReminderLogStatus::Sent) {
                throw new RuntimeException('db down');
            }
        });

        try {
            SendAdhkarReminderJob::dispatchSync($log->id);
        } catch (RuntimeException) {
            // The queue may surface the failed status update.
        }

        $fresh = $log->fresh();
        $this->assertNotSame(ReminderLogStatus::Sent, $fresh->status);
        $this->assertNotNull($fresh->last_dispatch_attempt_at);
        $sentNudge = collect(Http::recorded())->filter(
            fn ($pair) => str_contains((string) ($pair[0]['text'] ?? ''), 'حان وقت')
        );
        $this->assertCount(1, $sentNudge);

        SendAdhkarReminderJob::dispatchSync($log->id);

        $this->assertSame('nudge_unconfirmed', $log->fresh()->error_message);
        $sentAgain = collect(Http::recorded())->filter(
            fn ($pair) => str_contains((string) ($pair[0]['text'] ?? ''), 'حان وقت')
        );
        $this->assertCount(1, $sentAgain);
    }

    public function test_manual_today_does_not_create_a_log_or_done_button(): void
    {
        $user = $this->user();
        $before = ReminderLog::query()->count();

        app(TelegramUpdateProcessor::class)->handle(
            $this->telegramCallbackPayload('today:morning', 60, 1001)
        );

        $this->assertSame($before, ReminderLog::query()->count());
        Http::assertNotSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'reminder:done:'));
        Http::assertNotSent(fn ($request) => str_contains((string) ($request['reply_markup'] ?? ''), 'reminder:done:'));
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'الذكر 1 من'));
    }

    public function test_reading_pages_one_item_and_can_continue_into_the_full_wird(): void
    {
        $user = $this->user();
        $log = $this->sentLog($user, 'morning_short');
        $short = \App\Models\AdhkarCollection::query()->where('key', 'morning_short')->first();
        $last = $short->items()->count() - 1;

        $this->start($log, 80);

        $cards = $this->readingMessages();
        $this->assertCount(1, $cards);
        $this->assertStringContainsString('الذكر 1 من', $cards[0]);
        $this->assertStringNotContainsString('دقائق', $cards[0]);
        Http::assertSent(function ($request) use ($log) {
            $markup = (string) ($request['reply_markup'] ?? '');

            return str_contains($request->url(), 'sendMessage')
                && str_contains($markup, 'rd:'.$log->id.':s:1')
                && str_contains($markup, 'أرسل الورد كاملًا')
                && ! str_contains($markup, 'احفظ الورد')
                && ! str_contains($markup, 'reminder:done:');
        });

        app(TelegramUpdateProcessor::class)->handle(
            $this->telegramCallbackPayload('rd:'.$log->id.':s:1', 81, 1001)
        );
        Http::assertSent(function ($request) {
            $markup = json_decode((string) ($request['reply_markup'] ?? ''), true);
            $row = is_array($markup) ? ($markup['inline_keyboard'][0] ?? []) : [];

            return str_contains($request->url(), 'editMessageText')
                && str_contains((string) ($request['text'] ?? ''), 'الذكر 2 من')
                && ($row[0]['text'] ?? '') === 'السابق'
                && ($row[1]['text'] ?? '') === 'التالي';
        });

        app(TelegramUpdateProcessor::class)->handle(
            $this->telegramCallbackPayload('rd:'.$log->id.':s:'.$last, 82, 1001)
        );
        Http::assertSent(function ($request) use ($log, $last) {
            $markup = (string) ($request['reply_markup'] ?? '');

            return str_contains($request->url(), 'editMessageText')
                && str_contains($markup, 'rd:'.$log->id.':f:0')
                && str_contains($markup, 'reminder:done:'.$log->id)
                && str_contains($markup, 'أكمل الورد الكامل')
                && ! str_contains($markup, 'rd:'.$log->id.':s:'.($last + 1));
        });

        app(TelegramUpdateProcessor::class)->handle(
            $this->telegramCallbackPayload('rd:'.$log->id.':f:0', 83, 1001)
        );
        $this->assertSame(AdhkarMode::Short, $user->fresh()->mode);

        app(TelegramUpdateProcessor::class)->handle(
            $this->telegramCallbackPayload('sv:l:'.$log->id.':s', 84, 1001)
        );
        $this->assertSame(AdhkarMode::Short, $user->fresh()->mode);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendMessage')
                && str_contains((string) ($request['text'] ?? ''), 'حتى لو انقطع الاتصال')
                && ! str_contains((string) ($request['reply_markup'] ?? ''), 'inline_keyboard');
        });
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'سيد الاستغفار'));
        Http::assertSent(function ($request) use ($log, $last) {
            $markup = (string) ($request['reply_markup'] ?? '');

            return str_contains((string) ($request['text'] ?? ''), 'كامل')
                && str_contains((string) ($request['text'] ?? ''), 'الذكر 1 من')
                && str_contains($markup, 'rd:'.$log->id.':s:'.$last)
                && ! str_contains($markup, 'أكمل الورد الكامل');
        });
    }

    public function test_snapshot_matches_the_scheduled_collection(): void
    {
        $user = $this->user();
        $user->update(['mode' => AdhkarMode::Full]);
        $log = $this->sentLog($user, 'morning_short');
        $collection = \App\Models\AdhkarCollection::query()->where('key', 'morning_short')->first();
        $expected = app(AdhkarContentService::class)->snapshotVersions(
            $collection->items()->where('adhkar_items.active', true)->get()
        );

        $this->start($log);

        $this->assertSame($expected['content_snapshot_hash'], $log->fresh()->content_snapshot_hash);
    }

    public function test_main_keyboard_puts_today_on_the_right_and_changes_with_pause(): void
    {
        $user = $this->user();
        $keyboard = app(\App\Support\Telegram\KeyboardFactory::class)->mainReplyKeyboard($user);
        $top = $keyboard['keyboard'][0];
        $this->assertSame('📖 أذكار اليوم', $top[array_key_last($top)]['text']);

        app(TelegramUpdateProcessor::class)->handle($this->telegramMessagePayload('/pause', 70, 1001));
        Http::assertSent(function ($request) {
            $markup = json_decode((string) ($request['reply_markup'] ?? ''), true);

            return is_array($markup)
                && str_contains(json_encode($markup, JSON_UNESCAPED_UNICODE), '▶️ تفعيل التذكير');
        });
    }

    private function user(int $telegramId = 1001): User
    {
        return User::factory()->create([
            'telegram_id' => $telegramId,
            'chat_id' => $telegramId,
            'timezone' => 'Asia/Damascus',
            'timezone_label' => 'سوريا',
            'mode' => AdhkarMode::Short,
        ]);
    }

    private function sentLog(User $user, string $collectionKey): ReminderLog
    {
        return ReminderLog::factory()->create([
            'user_id' => $user->id,
            'reminder_type' => str_starts_with($collectionKey, 'evening') ? ReminderType::Evening : ReminderType::Morning,
            'collection_key' => $collectionKey,
            'status' => ReminderLogStatus::Sent,
            'sent_at' => now(),
            'scheduled_local_date' => now()->timezone($user->timezone)->toDateString(),
        ]);
    }

    private function start(ReminderLog $log, int $updateId = 10): void
    {
        app(TelegramUpdateProcessor::class)->handle(
            $this->telegramCallbackPayload('reminder:start:'.$log->id, $updateId, $log->user->telegram_id)
        );
    }

    private function done(ReminderLog $log, int $updateId): void
    {
        app(TelegramUpdateProcessor::class)->handle(
            $this->telegramCallbackPayload('reminder:done:'.$log->id, $updateId, $log->user->telegram_id)
        );
    }

    /**
     * @return array<int, string>
     */
    private function readingMessages(): array
    {
        return collect(Http::recorded())
            ->map(fn ($pair) => (string) ($pair[0]['text'] ?? ''))
            ->filter(fn (string $text) => str_contains($text, 'الذكر'))
            ->values()
            ->all();
    }
}
