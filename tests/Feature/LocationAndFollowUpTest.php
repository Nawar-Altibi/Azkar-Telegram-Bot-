<?php

namespace Tests\Feature;

use App\Enums\AdhkarMode;
use App\Enums\OnboardingState;
use App\Enums\ReminderLogStatus;
use App\Enums\ReminderType;
use App\Models\ReadingSession;
use App\Models\ReminderLog;
use App\Models\User;
use App\Services\ContentSyncService;
use App\Services\CronTickService;
use App\Services\FollowUpReminderService;
use App\Services\StaleRecoveryService;
use App\Services\Telegram\TelegramUpdateProcessor;
use App\Support\NearestTimezone;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LocationAndFollowUpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 5]], 200),
        ]);
    }

    public function test_nearest_timezone_comes_from_the_bundled_database(): void
    {
        $this->assertSame('Asia/Damascus', NearestTimezone::find(33.51, 36.29));
        $this->assertSame('Africa/Cairo', NearestTimezone::find(30.04, 31.24));
        $this->assertSame('Europe/Istanbul', NearestTimezone::find(41.01, 28.97));
    }

    public function test_setup_can_find_the_country_from_a_shared_location_after_confirming_the_time(): void
    {
        $this->travelTo(Carbon::parse('2026-03-10 16:26:00', 'Asia/Damascus'));
        $this->message('/start', 1);
        $this->press('onb:begin', 2);
        Http::assertSent(fn ($request) => str_contains((string) ($request['reply_markup'] ?? ''), 'onb:loc'));

        $this->press('onb:loc', 3);
        $user = User::query()->where('telegram_id', 1001)->first();
        $this->assertSame('onboarding:location', $user->awaiting_input);
        Http::assertSent(fn ($request) => str_contains((string) ($request['reply_markup'] ?? ''), 'request_location')
            && str_contains((string) ($request['text'] ?? ''), 'لا نحفظ موقعك'));

        $this->location(33.51, 36.29, 4);
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'الساعة عندك الآن 4:26 عصرًا')
            && str_contains((string) ($request['text'] ?? ''), 'سوريا')
            && str_contains((string) ($request['reply_markup'] ?? ''), 'loc:yes'));
        $this->assertNull($user->fresh()->timezone);

        $this->press('loc:yes', 5);
        $user->refresh();
        $this->assertSame('Asia/Damascus', $user->timezone);
        $this->assertSame(OnboardingState::WaitingMorningTime, $user->onboarding_state);
        $this->assertNull($user->awaiting_input);
    }

    public function test_choosing_from_the_list_instead_keeps_the_normal_country_screen(): void
    {
        $user = User::factory()->create(['telegram_id' => 1001, 'chat_id' => 1001, 'onboarding_state' => OnboardingState::WaitingTimezone]);
        $this->press('onb:loc', 1);

        $this->message('🌍 سأختار من القائمة', 2);

        $this->assertNull($user->fresh()->awaiting_input);
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'اختر منطقتك')
            && str_contains((string) ($request['reply_markup'] ?? ''), 'onb:tz:0'));
    }

    public function test_settings_location_change_and_unrequested_locations(): void
    {
        $user = $this->onboardedUser(['timezone' => 'Asia/Riyadh']);

        $this->location(33.51, 36.29, 1);
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'لم أطلبه'));
        $this->assertSame('Asia/Riyadh', $user->fresh()->timezone);

        $this->press('onb:loc', 2);
        $this->location(30.04, 31.24, 3);
        $this->press('loc:no', 4);
        $this->assertSame('Asia/Riyadh', $user->fresh()->timezone);

        $this->press('onb:loc', 5);
        $this->location(30.04, 31.24, 6);
        $this->press('loc:yes', 7);
        $this->assertSame('Africa/Cairo', $user->fresh()->timezone);
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'أعدنا لك الأزرار'));
    }

    public function test_one_follow_up_is_sent_an_hour_later_while_the_period_is_still_current(): void
    {
        $this->travelTo(Carbon::parse('2026-03-10 07:10:00', 'Asia/Damascus'));
        $user = $this->onboardedUser(['follow_up_enabled' => true, 'mode' => AdhkarMode::Full, 'mode_chosen_at' => now()->subDay()]);
        $log = $this->sentLog($user, now()->subMinutes(70));

        $this->assertSame(1, app(FollowUpReminderService::class)->sendDue());
        $this->assertNotNull($log->fresh()->follow_up_sent_at);
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'ما زال في وقت أذكار الصباح متّسع')
            && str_contains((string) ($request['reply_markup'] ?? ''), 'reminder:start:'.$log->id.':a')
            && str_contains((string) ($request['reply_markup'] ?? ''), 'settings:follow_up_off'));

        $this->assertSame(0, app(FollowUpReminderService::class)->sendDue());

        $this->press('settings:follow_up_off', 9);
        $this->assertFalse($user->fresh()->follow_up_enabled);
    }

    public function test_cron_tick_sends_follow_ups_together_with_stale_recovery(): void
    {
        $this->travelTo(Carbon::parse('2026-03-10 07:10:00', 'Asia/Damascus'));
        $user = $this->onboardedUser(['follow_up_enabled' => true]);
        $this->sentLog($user, now()->subMinutes(70));
        Cache::forget(StaleRecoveryService::MARKER_KEY);

        $metrics = app(CronTickService::class)->run();

        $this->assertTrue($metrics['stale_recovery_ran']);
        $this->assertSame(1, $metrics['follow_ups_sent']);
    }

    public function test_no_follow_up_when_off_too_early_already_used_or_the_period_passed(): void
    {
        $this->travelTo(Carbon::parse('2026-03-10 07:10:00', 'Asia/Damascus'));
        $service = app(FollowUpReminderService::class);

        $off = $this->onboardedUser(['telegram_id' => 2001, 'chat_id' => 2001, 'follow_up_enabled' => false]);
        $this->sentLog($off, now()->subMinutes(70));

        $early = $this->onboardedUser(['telegram_id' => 2002, 'chat_id' => 2002, 'follow_up_enabled' => true]);
        $this->sentLog($early, now()->subMinutes(30));

        $active = $this->onboardedUser(['telegram_id' => 2003, 'chat_id' => 2003, 'follow_up_enabled' => true, 'last_interaction_at' => now()->subMinutes(10)]);
        $this->sentLog($active, now()->subMinutes(70));

        $this->assertSame(0, $service->sendDue());

        $late = $this->onboardedUser(['telegram_id' => 2004, 'chat_id' => 2004, 'follow_up_enabled' => true]);
        $this->sentLog($late, now()->subMinutes(70));
        $this->travelTo(Carbon::parse('2026-03-10 13:00:00', 'Asia/Damascus'));
        $this->assertSame(0, $service->sendDue());
    }

    public function test_reading_the_wird_by_hand_before_the_reminder_closes_the_follow_up(): void
    {
        $this->travelTo(Carbon::parse('2026-03-10 05:30:00', 'Asia/Damascus'));
        app(ContentSyncService::class)->sync();
        $user = $this->onboardedUser([
            'follow_up_enabled' => true,
            'mode' => AdhkarMode::Short,
            'mode_chosen_at' => now()->subDay(),
        ]);
        $this->message('🌅 أذكار الصباح', 1);

        $this->travelTo(Carbon::parse('2026-03-10 07:10:00', 'Asia/Damascus'));
        $log = $this->sentLog($user, now()->subMinutes(70));

        $this->assertSame(0, app(FollowUpReminderService::class)->sendDue());
        $this->assertNotNull($log->fresh()->follow_up_sent_at);
        Http::assertNotSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'ما زال في وقت أذكار الصباح'));
    }

    public function test_follow_up_still_goes_out_during_the_hour_after_the_period_ends(): void
    {
        $this->travelTo(Carbon::parse('2026-03-10 12:10:00', 'Asia/Damascus'));
        $morning = $this->onboardedUser(['follow_up_enabled' => true, 'last_interaction_at' => now()->subDay()]);
        $this->sentLog($morning, now()->subMinutes(70));

        $this->assertSame(1, app(FollowUpReminderService::class)->sendDue());

        $this->travelTo(Carbon::parse('2026-03-10 21:20:00', 'Asia/Damascus'));
        $evening = $this->onboardedUser([
            'telegram_id' => 3001,
            'chat_id' => 3001,
            'follow_up_enabled' => true,
            'last_interaction_at' => now()->subDay(),
        ]);
        ReminderLog::factory()->create([
            'user_id' => $evening->id,
            'reminder_type' => ReminderType::Evening,
            'collection_key' => 'evening_short',
            'status' => ReminderLogStatus::Sent,
            'sent_at' => now()->subMinutes(70),
            'scheduled_local_date' => '2026-03-10',
        ]);

        $this->assertSame(1, app(FollowUpReminderService::class)->sendDue());
    }

    public function test_follow_up_closes_after_the_extra_hour(): void
    {
        $this->travelTo(Carbon::parse('2026-03-10 13:10:00', 'Asia/Damascus'));
        $user = $this->onboardedUser(['follow_up_enabled' => true, 'last_interaction_at' => now()->subDay()]);
        $log = $this->sentLog($user, now()->subHours(2)->subMinutes(10));

        $this->assertSame(0, app(FollowUpReminderService::class)->sendDue());
        $this->assertNotNull($log->fresh()->follow_up_sent_at);
        Http::assertNothingSent();
    }

    public function test_reading_another_period_still_allows_the_follow_up(): void
    {
        $this->travelTo(Carbon::parse('2026-03-10 07:10:00', 'Asia/Damascus'));
        $user = $this->onboardedUser(['follow_up_enabled' => true]);
        ReadingSession::query()->create([
            'user_id' => $user->id,
            'collection_key' => 'evening_short',
            'phase' => 's',
            'source' => 'manual',
            'total' => 9,
            'furthest' => 1,
            'started_at' => now()->subMinutes(100),
            'last_seen_at' => now()->subMinutes(90),
        ]);
        $this->sentLog($user, now()->subMinutes(70));

        $this->assertSame(1, app(FollowUpReminderService::class)->sendDue());
    }

    public function test_settings_toggle_turns_the_follow_up_on_and_off(): void
    {
        $user = $this->onboardedUser(['follow_up_enabled' => false]);

        $this->press('settings:follow_up', 1);
        $this->assertTrue($user->fresh()->follow_up_enabled);
        Http::assertSent(fn ($request) => str_contains((string) ($request['reply_markup'] ?? ''), 'تذكير ثانٍ إن نسيت: مفعّل'));

        $this->press('settings:follow_up', 2);
        $this->assertFalse($user->fresh()->follow_up_enabled);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function onboardedUser(array $overrides = []): User
    {
        return User::factory()->create($overrides + [
            'telegram_id' => 1001,
            'chat_id' => 1001,
            'timezone' => 'Asia/Damascus',
            'timezone_label' => 'سوريا',
            'morning_time' => '06:00',
            'evening_time' => '17:00',
            'onboarding_state' => OnboardingState::Completed,
            'reminders_enabled' => true,
            'last_interaction_at' => now()->subDay(),
        ]);
    }

    private function sentLog(User $user, Carbon $sentAt): ReminderLog
    {
        return ReminderLog::factory()->create([
            'user_id' => $user->id,
            'reminder_type' => ReminderType::Morning,
            'collection_key' => 'morning_short',
            'status' => ReminderLogStatus::Sent,
            'sent_at' => $sentAt,
            'scheduled_local_date' => now()->timezone('Asia/Damascus')->toDateString(),
        ]);
    }

    private function message(string $text, int $updateId): void
    {
        app(TelegramUpdateProcessor::class)->handle($this->telegramMessagePayload($text, $updateId, 1001));
    }

    private function press(string $data, int $updateId): void
    {
        app(TelegramUpdateProcessor::class)->handle($this->telegramCallbackPayload($data, $updateId, 1001));
    }

    private function location(float $latitude, float $longitude, int $updateId): void
    {
        app(TelegramUpdateProcessor::class)->handle(
            $this->telegramMediaPayload(['location' => ['latitude' => $latitude, 'longitude' => $longitude]], $updateId, 1001)
        );
    }
}
