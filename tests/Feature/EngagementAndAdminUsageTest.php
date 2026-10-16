<?php

namespace Tests\Feature;

use App\Enums\AdhkarMode;
use App\Enums\OnboardingState;
use App\Enums\ReminderLogStatus;
use App\Enums\ReminderType;
use App\Models\ReadingSession;
use App\Models\ReminderLog;
use App\Models\User;
use App\Services\AdminUserUsageService;
use App\Services\ContentSyncService;
use App\Services\EngagementMessageService;
use App\Services\Telegram\TelegramUpdateProcessor;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EngagementAndAdminUsageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 5]], 200),
        ]);
        app(ContentSyncService::class)->sync();
        config(['telegram.admin_chat_id' => 9001]);
    }

    public function test_weekly_summary_toggle_in_settings(): void
    {
        $user = $this->user(['weekly_summary_enabled' => true]);

        app(TelegramUpdateProcessor::class)->handle($this->telegramCallbackPayload('settings:weekly', 1, (int) $user->telegram_id));

        $this->assertFalse($user->fresh()->weekly_summary_enabled);
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'لن نرسل الملخص الأسبوعي'));
    }

    public function test_time_fit_ask_after_three_unopened_reminders(): void
    {
        $this->travelTo('2026-10-16 12:00:00');
        $user = $this->user([
            'timezone' => 'UTC',
            'morning_time' => '06:00:00',
            'evening_time' => '18:00:00',
            'weekly_summary_enabled' => false,
            'last_interaction_at' => now()->subDay(),
        ]);

        foreach ([1, 2, 3] as $i) {
            ReminderLog::factory()->create([
                'user_id' => $user->id,
                'reminder_type' => ReminderType::Morning,
                'collection_key' => 'morning_short',
                'scheduled_local_date' => now()->subDays($i)->toDateString(),
                'scheduled_for' => now()->subDays($i)->setTime(6, 0),
                'status' => ReminderLogStatus::Sent,
                'sent_at' => now()->subDays($i)->setTime(6, 0),
                'reading_started_at' => null,
                'completed_at' => null,
            ]);
        }

        $counts = app(EngagementMessageService::class)->sendDue();

        $this->assertSame(1, $counts['time_fit']);
        $this->assertNotNull($user->fresh()->time_fit_asked_at);
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'هل وقت التذكير')
            && str_contains((string) ($request['reply_markup'] ?? ''), 'time_fit:ok'));
    }

    public function test_weekly_summary_waits_one_week_on_bot_then_every_week(): void
    {
        $this->travelTo('2026-10-16 12:30:00');
        $user = $this->user([
            'timezone' => 'UTC',
            'morning_time' => '06:00:00',
            'evening_time' => '18:00:00',
            'weekly_summary_enabled' => true,
            'last_weekly_summary_at' => null,
            'last_interaction_at' => now(),
            'created_at' => now()->subDays(6),
        ]);

        ReminderLog::factory()->create([
            'user_id' => $user->id,
            'reminder_type' => ReminderType::Morning,
            'collection_key' => 'morning_short',
            'scheduled_local_date' => '2026-10-15',
            'scheduled_for' => now()->subDay(),
            'status' => ReminderLogStatus::Completed,
            'sent_at' => now()->subDay(),
            'completed_at' => now()->subDay(),
        ]);

        $this->assertSame(0, app(EngagementMessageService::class)->sendDue()['weekly']);
        $this->assertNull($user->fresh()->last_weekly_summary_at);

        $user->forceFill(['created_at' => now()->subDays(7)])->save();

        $counts = app(EngagementMessageService::class)->sendDue();
        $this->assertSame(1, $counts['weekly']);
        $firstSentAt = $user->fresh()->last_weekly_summary_at;
        $this->assertNotNull($firstSentAt);
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'حصاد أسبوعك')
            && str_contains((string) ($request['text'] ?? ''), '🟢'));

        $this->assertSame(0, app(EngagementMessageService::class)->sendDue()['weekly']);

        $this->travelTo($firstSentAt->copy()->addDays(7)->setTime(12, 30));
        ReminderLog::factory()->create([
            'user_id' => $user->id,
            'reminder_type' => ReminderType::Evening,
            'collection_key' => 'evening_short',
            'scheduled_local_date' => now()->subDay()->toDateString(),
            'scheduled_for' => now()->subDay(),
            'status' => ReminderLogStatus::Completed,
            'sent_at' => now()->subDay(),
            'completed_at' => now()->subDay(),
        ]);

        $this->assertSame(1, app(EngagementMessageService::class)->sendDue()['weekly']);
        $this->assertTrue($user->fresh()->last_weekly_summary_at->gt($firstSentAt));
    }

    public function test_comeback_after_two_weeks_away(): void
    {
        $this->travelTo('2026-10-16 12:00:00');
        $user = $this->user([
            'timezone' => 'UTC',
            'morning_time' => '06:00:00',
            'evening_time' => '18:00:00',
            'weekly_summary_enabled' => false,
            'last_interaction_at' => now()->subDays(15),
        ]);

        $counts = app(EngagementMessageService::class)->sendDue();

        $this->assertSame(1, $counts['comeback']);
        $this->assertNotNull($user->fresh()->comeback_sent_at);
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'أهلًا بعودتك')
            && str_contains((string) ($request['reply_markup'] ?? ''), 'comeback:continue'));
    }

    public function test_feedback_nudge_uses_the_agreed_copy(): void
    {
        $this->travelTo('2026-10-16 12:00:00');
        $user = $this->user([
            'timezone' => 'UTC',
            'morning_time' => '06:00:00',
            'evening_time' => '18:00:00',
            'weekly_summary_enabled' => false,
            'created_at' => now()->subDays(10),
            'last_interaction_at' => now()->subDay(),
        ]);
        ReadingSession::query()->create([
            'user_id' => $user->id,
            'collection_key' => 'morning_short',
            'phase' => 's',
            'source' => 'manual',
            'total' => 9,
            'furthest' => 2,
            'started_at' => now()->subDays(2),
            'last_seen_at' => now()->subDays(2),
        ]);
        ReadingSession::query()->create([
            'user_id' => $user->id,
            'collection_key' => 'evening_short',
            'phase' => 's',
            'source' => 'manual',
            'total' => 9,
            'furthest' => 3,
            'started_at' => now()->subDay(),
            'last_seen_at' => now()->subDay(),
        ]);

        $counts = app(EngagementMessageService::class)->sendDue();

        $this->assertSame(1, $counts['feedback']);
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'شكرًا لأن أَنِيس جزء من أيامك')
            && str_contains((string) ($request['reply_markup'] ?? ''), 'في وقتٍ آخر')
            && str_contains((string) ($request['reply_markup'] ?? ''), 'feedback:later'));
    }

    public function test_admin_user_command_shows_card_times(): void
    {
        $target = $this->user([
            'telegram_id' => 2001,
            'chat_id' => 2001,
            'username' => 'reader1',
            'timezone' => 'UTC',
        ]);
        ReadingSession::query()->create([
            'user_id' => $target->id,
            'collection_key' => 'morning_short',
            'phase' => 's',
            'source' => 'manual',
            'total' => 9,
            'furthest' => 2,
            'card_times' => [
                '1' => now()->subHour()->toIso8601String(),
                '2' => now()->subMinutes(50)->toIso8601String(),
            ],
            'started_at' => now()->subHour(),
            'last_seen_at' => now()->subMinutes(50),
        ]);

        $admin = $this->user(['telegram_id' => 9001, 'chat_id' => 9001, 'username' => 'admin']);
        app(TelegramUpdateProcessor::class)->handle($this->telegramMessagePayload('/user @reader1', 1, 9001));

        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'استخدام @reader1')
            && str_contains((string) ($request['text'] ?? ''), 'الذكر 1')
            && str_contains((string) ($request['text'] ?? ''), 'أذكار الصباح'));

        $chunks = app(AdminUserUsageService::class)->report('reader1');
        $this->assertNotEmpty($chunks);
        $this->assertStringContainsString('reader1', $chunks[0]);
        $this->assertSame($admin->id, User::query()->where('chat_id', 9001)->value('id'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function user(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'onboarding_state' => OnboardingState::Completed,
            'timezone' => 'Asia/Damascus',
            'morning_time' => '06:00:00',
            'evening_time' => '18:00:00',
            'mode' => AdhkarMode::Short,
            'reminders_enabled' => true,
            'last_interaction_at' => now(),
        ], $overrides));
    }
}
