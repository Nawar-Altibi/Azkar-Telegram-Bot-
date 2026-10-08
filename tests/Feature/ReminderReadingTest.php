<?php

namespace Tests\Feature;

use App\Enums\AdhkarMode;
use App\Enums\ReminderLogStatus;
use App\Enums\ReminderType;
use App\Jobs\SendAdhkarReminderJob;
use App\Models\AdhkarCollection;
use App\Models\ReminderLog;
use App\Models\User;
use App\Services\AdhkarContentService;
use App\Services\ContentSyncService;
use App\Services\Telegram\TelegramUpdateProcessor;
use App\Support\Telegram\KeyboardFactory;
use Carbon\Carbon;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
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
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'بدأت هذه الأذكار من قبل'));
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
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'كان هذا التذكير لوقت قد انتهى'));
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
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'كان هذا التذكير لوقت قد انتهى'));
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
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 1]])
                ->push(['ok' => false, 'description' => 'Bad Request: secret-api-error'], 400)
                ->push(['ok' => false, 'description' => 'Bad Request: secret-api-error'], 400)
                ->push(['ok' => true, 'result' => ['message_id' => 4]]),
        ]);
        $user = $this->user();
        $log = $this->sentLog($user, 'morning_short');

        $this->start($log, 50);

        $log->refresh();
        $this->assertNotNull($log->reading_started_at);
        $this->assertNotNull($log->content_snapshot_hash);
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'لم تصل الأذكار'));
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
        Http::assertSent(function ($request) {
            $markup = (string) ($request['reply_markup'] ?? '');

            return str_contains($request->url(), 'editMessageText')
                && str_contains((string) ($request['text'] ?? ''), 'أي ورد تحب أن تقرأ')
                && str_contains($markup, 'md:m:s')
                && str_contains($markup, 'md:m:a');
        });

        app(TelegramUpdateProcessor::class)->handle(
            $this->telegramCallbackPayload('rt:m:a:0', 61, 1001)
        );

        $this->assertSame($before, ReminderLog::query()->count());
        $this->assertSame(AdhkarMode::Short, $user->fresh()->mode);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'editMessageText')
            && str_contains((string) ($request['text'] ?? ''), 'كامل')
            && str_contains((string) ($request['text'] ?? ''), 'الذكر 1 من'));
        Http::assertNotSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'reminder:done:'));
        Http::assertNotSent(fn ($request) => str_contains((string) ($request['reply_markup'] ?? ''), 'reminder:done:'));
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'الذكر 1 من'));
    }

    public function test_reading_pages_one_item_and_can_continue_into_the_full_wird(): void
    {
        $user = $this->user();
        $log = $this->sentLog($user, 'morning_short');
        $short = AdhkarCollection::query()->where('key', 'morning_short')->first();
        $last = $short->items()->count() - 1;

        $this->start($log, 80);

        $cards = $this->readingMessages();
        $this->assertCount(1, $cards);
        $this->assertStringContainsString('الذكر 1 من', $cards[0]);
        $this->assertStringNotContainsString('دقائق', $cards[0]);
        Http::assertSent(function ($request) use ($log) {
            $markup = (string) ($request['reply_markup'] ?? '');

            return str_contains($request->url(), 'editMessageText')
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
                && ($row[0]['text'] ?? '') === '⬅️ التالي'
                && ($row[1]['text'] ?? '') === 'السابق ➡️';
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
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendDocument')
            && str_contains($this->multipartField($request, 'caption'), 'أذكار الصباح — مختصر')
            && str_starts_with($this->multipartField($request, 'document'), '%PDF'));
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
        $collection = AdhkarCollection::query()->where('key', 'morning_short')->first();
        $expected = app(AdhkarContentService::class)->snapshotVersions(
            $collection->items()->where('adhkar_items.active', true)->get()
        );

        $this->start($log);

        $this->assertSame($expected['content_snapshot_hash'], $log->fresh()->content_snapshot_hash);
    }

    public function test_main_keyboard_puts_morning_on_the_right_and_offers_undo_after_pause(): void
    {
        $user = $this->user();
        $keyboard = app(KeyboardFactory::class)->mainReplyKeyboard($user);
        $top = $keyboard['keyboard'][0];
        $this->assertSame('🌅 أذكار الصباح', $top[array_key_last($top)]['text']);
        $this->assertStringNotContainsString('إيقاف', json_encode($keyboard, JSON_UNESCAPED_UNICODE));

        app(TelegramUpdateProcessor::class)->handle($this->telegramMessagePayload('/pause', 70, 1001));
        $this->assertFalse($user->fresh()->reminders_enabled);
        Http::assertSent(fn ($request) => str_contains((string) ($request['reply_markup'] ?? ''), 'settings:resume'));
    }

    public function test_choosing_full_from_the_reminder_reads_the_full_wird_in_the_same_message(): void
    {
        $user = $this->user();
        $log = $this->sentLog($user, 'morning_short');

        app(TelegramUpdateProcessor::class)->handle(
            $this->telegramCallbackPayload('reminder:start:'.$log->id.':a', 90, 1001)
        );

        $log->refresh();
        $this->assertSame('morning_full', $log->collection_key);
        $this->assertNotNull($log->reading_started_at);
        $this->assertContains('morning.10', array_column($log->content_version_map, 'key'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'editMessageText')
            && (int) ($request['message_id'] ?? 0) === 10
            && str_contains((string) ($request['text'] ?? ''), 'كامل')
            && str_contains((string) ($request['reply_markup'] ?? ''), 'rd:'.$log->id.':a:1'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'sendMessage'));
    }

    public function test_pdf_is_uploaded_once_then_resent_by_file_id(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 9, 'document' => ['file_id' => 'FILE-123']],
            ]),
        ]);
        $this->user();

        app(TelegramUpdateProcessor::class)->handle($this->telegramCallbackPayload('sv:t:e:a', 95, 1001));
        app(TelegramUpdateProcessor::class)->handle($this->telegramCallbackPayload('sv:t:e:a', 96, 1001));

        $documents = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'sendDocument'))->values();
        $this->assertCount(2, $documents);
        $this->assertStringContainsString('أذكار المساء — كامل', $this->multipartField($documents[0][0], 'caption'));
        $this->assertSame('FILE-123', $documents[1][0]['document'] ?? null);
    }

    private function multipartField(Request $request, string $name): string
    {
        foreach ($request->data() as $part) {
            if (is_array($part) && ($part['name'] ?? null) === $name) {
                return (string) ($part['contents'] ?? '');
            }
        }

        return '';
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
