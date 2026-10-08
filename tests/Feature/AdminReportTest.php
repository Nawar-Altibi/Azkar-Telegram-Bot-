<?php

namespace Tests\Feature;

use App\Enums\AdhkarMode;
use App\Models\BotEvent;
use App\Models\ReadingSession;
use App\Models\User;
use App\Services\AdminReportService;
use App\Services\ContentSyncService;
use App\Services\Telegram\TelegramUpdateProcessor;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminReportTest extends TestCase
{
    private const ADMIN = 777;

    protected function setUp(): void
    {
        parent::setUp();
        config(['telegram.double_press_seconds' => 0]);
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 5]], 200),
        ]);
        app(ContentSyncService::class)->sync();
    }

    public function test_paging_a_wird_tracks_one_session_up_to_the_end(): void
    {
        $user = $this->user();

        $this->handle($this->telegramMessagePayload('🌅 أذكار الصباح', 1));
        $session = ReadingSession::query()->sole();
        $this->assertSame('morning_short', $session->collection_key);
        $this->assertSame('manual', $session->source);
        $this->assertSame(1, (int) $session->furthest);
        $this->assertNotNull($session->local_hour);

        $this->handle($this->telegramCallbackPayload('rt:m:s:1', 2));
        $this->handle($this->telegramCallbackPayload('rt:m:s:0', 3));
        $this->assertSame(1, ReadingSession::query()->count());
        $this->assertSame(2, (int) ReadingSession::query()->sole()->furthest);

        $last = (int) $session->total - 1;
        $this->handle($this->telegramCallbackPayload("rt:m:s:{$last}", 4));
        $this->assertNotNull(ReadingSession::query()->sole()->completed_at);
        $this->assertSame($user->id, (int) ReadingSession::query()->sole()->user_id);
    }

    public function test_switching_on_the_first_card_replaces_the_session(): void
    {
        $this->user();

        $this->handle($this->telegramMessagePayload('🌅 أذكار الصباح', 1));
        $this->handle($this->telegramCallbackPayload('md:m:a', 2));

        $session = ReadingSession::query()->sole();
        $this->assertSame('morning_full', $session->collection_key);
        $this->assertSame('a', $session->phase);
    }

    public function test_stats_answer_only_the_admin_chat(): void
    {
        config(['telegram.admin_chat_id' => self::ADMIN]);
        $this->user();
        User::factory()->create(['telegram_id' => self::ADMIN, 'chat_id' => self::ADMIN]);

        $this->handle($this->telegramMessagePayload('/stats', 1));
        Http::assertNotSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'تقرير آخر'));

        $this->handle($this->telegramMessagePayload('/stats 30', 2, self::ADMIN));
        Http::assertSent(fn ($request) => (int) ($request['chat_id'] ?? 0) === self::ADMIN
            && str_contains((string) $request['text'], 'تقرير آخر 30 أيام')
            && str_contains((string) $request['text'], 'المستخدمون'));
    }

    public function test_report_shows_where_readers_stop_and_what_they_open(): void
    {
        $user = $this->user();
        foreach ([3, 3, 3, 5] as $furthest) {
            ReadingSession::query()->create([
                'user_id' => $user->id,
                'collection_key' => 'evening_full',
                'phase' => 'a',
                'source' => 'manual',
                'total' => 19,
                'furthest' => $furthest,
                'local_hour' => 21,
                'started_at' => now()->subHours(3),
                'last_seen_at' => now()->subHours(3),
            ]);
        }
        BotEvent::query()->create(['user_id' => $user->id, 'event' => 'help_topic', 'meta' => ['topic' => 'night'], 'created_at' => now()]);

        $report = app(AdminReportService::class)->build(7);

        $this->assertStringContainsString('أذكار المساء (كامل): 4 لم يُكملوا · أكثر توقف عند الذكر 3 من 19 (3 منهم)', $report);
        $this->assertStringContainsString('9:00 مساءً (4)', $report);
        $this->assertStringContainsString('الوضع الليلي لراحة عينيك (1)', $report);
    }

    public function test_weekly_report_goes_out_once_on_friday_morning(): void
    {
        config(['telegram.admin_chat_id' => self::ADMIN, 'telegram.admin_timezone' => 'Asia/Damascus']);
        $reports = app(AdminReportService::class);

        $this->travelTo(Carbon::parse('2026-10-09 09:30', 'Asia/Damascus'));
        $this->assertTrue($reports->isDue(), 'Last Friday\'s report was never sent.');
        $this->assertTrue($reports->sendWeeklyIfDue());
        $this->assertFalse($reports->isDue());
        $this->assertTrue($reports->nextDueAt()->eq(Carbon::parse('2026-10-09 10:00', 'Asia/Damascus')));

        $this->travelTo(Carbon::parse('2026-10-09 10:05', 'Asia/Damascus'));
        $this->assertTrue($reports->sendWeeklyIfDue());
        $this->assertFalse($reports->sendWeeklyIfDue());
        $this->assertTrue($reports->nextDueAt()->eq(Carbon::parse('2026-10-16 10:00', 'Asia/Damascus')));

        Http::assertSentCount(2);
    }

    public function test_no_admin_means_no_weekly_report(): void
    {
        config(['telegram.admin_chat_id' => null]);

        $this->assertNull(app(AdminReportService::class)->nextDueAt());
        $this->assertFalse(app(AdminReportService::class)->sendWeeklyIfDue());
    }

    public function test_blocking_the_bot_is_recorded(): void
    {
        $user = $this->user();

        $this->handle([
            'update_id' => 9,
            'my_chat_member' => [
                'chat' => ['id' => 1001, 'type' => 'private'],
                'from' => ['id' => 1001, 'is_bot' => false, 'first_name' => 'نوار'],
                'date' => time(),
                'old_chat_member' => ['status' => 'member', 'user' => ['id' => 1, 'is_bot' => true]],
                'new_chat_member' => ['status' => 'kicked', 'user' => ['id' => 1, 'is_bot' => true]],
            ],
        ]);

        $this->assertTrue(BotEvent::query()->where('user_id', $user->id)->where('event', 'bot_blocked')->exists());
    }

    private function user(): User
    {
        return User::factory()->create([
            'telegram_id' => 1001,
            'chat_id' => 1001,
            'timezone' => 'Asia/Damascus',
            'mode' => AdhkarMode::Short,
            'mode_chosen_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handle(array $payload): void
    {
        app(TelegramUpdateProcessor::class)->handle($payload);
    }
}
