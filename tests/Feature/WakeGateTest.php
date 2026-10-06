<?php

namespace Tests\Feature;

use App\Enums\ReminderLogStatus;
use App\Enums\ReminderType;
use App\Models\ReminderLog;
use App\Models\User;
use App\Services\FollowUpReminderService;
use App\Services\StaleRecoveryService;
use App\Services\WakeGate;
use App\Services\WakeScheduleService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WakeGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'sync', 'services.wake_gate.url' => 'https://gate.test']);
        $this->travelTo(Carbon::parse('2026-10-01 12:02:30'));
        Cache::forever(StaleRecoveryService::MARKER_KEY, now()->getTimestamp());
    }

    private function nextWake(): ?Carbon
    {
        return app(WakeScheduleService::class)->nextWakeAt();
    }

    private function user(array $overrides = []): User
    {
        return User::factory()->create($overrides + [
            'timezone' => 'UTC',
            'reminders_enabled' => true,
            'follow_up_enabled' => true,
            'next_morning_at' => now()->addHours(3),
            'next_evening_at' => now()->addHours(6),
        ]);
    }

    private function sentLog(User $user, Carbon $sentAt): ReminderLog
    {
        return ReminderLog::factory()->create([
            'user_id' => $user->id,
            'reminder_type' => ReminderType::Morning,
            'scheduled_local_date' => $sentAt->toDateString(),
            'status' => ReminderLogStatus::Sent,
            'sent_at' => $sentAt,
        ]);
    }

    public function test_nothing_scheduled_returns_null(): void
    {
        $this->assertNull($this->nextWake());
    }

    public function test_earliest_reminder_of_enabled_users_wins(): void
    {
        $this->user(['next_morning_at' => now()->addHours(2)]);
        $this->user(['next_evening_at' => now()->addMinutes(40)]);
        $this->user(['reminders_enabled' => false, 'next_morning_at' => now()->addMinutes(5)]);

        $this->assertEquals(now()->addMinutes(40)->startOfSecond(), $this->nextWake());
    }

    public function test_open_follow_up_waits_for_the_recovery_cadence(): void
    {
        $user = $this->user();
        $this->sentLog($user, now()->subMinutes(58));

        // Due in 2 minutes, but follow-ups are sent by the recovery pass, next due in 10.
        $this->assertEquals(
            Carbon::createFromTimestamp(now()->getTimestamp())->addMinutes(StaleRecoveryService::INTERVAL_MINUTES),
            $this->nextWake(),
        );
    }

    public function test_follow_up_is_ignored_when_user_turned_it_off(): void
    {
        $user = $this->user(['follow_up_enabled' => false]);
        $this->sentLog($user, now()->subMinutes(30));

        $this->assertEquals(now()->addHours(3)->startOfSecond(), $this->nextWake());
    }

    public function test_pending_reminder_wakes_for_recovery(): void
    {
        $user = $this->user();
        ReminderLog::factory()->create([
            'user_id' => $user->id,
            'status' => ReminderLogStatus::Pending,
        ]);

        $this->assertEquals(app(StaleRecoveryService::class)->nextRunAt(), $this->nextWake());
    }

    public function test_skipped_follow_up_is_closed_and_no_longer_wakes_the_bot(): void
    {
        Http::fake();
        $user = $this->user(['last_interaction_at' => now()]);
        $log = $this->sentLog($user, now()->subMinutes(70));

        $this->assertSame(0, app(FollowUpReminderService::class)->sendDue());

        $this->assertNotNull($log->fresh()->follow_up_sent_at);
        $this->assertNull(app(FollowUpReminderService::class)->nextDueAt());
        Http::assertNothingSent();
    }

    public function test_schedule_change_is_pushed_once_after_the_request(): void
    {
        Http::fake(['https://gate.test/*' => Http::response(['ok' => true])]);
        $user = $this->user();

        $user->update(['next_morning_at' => now()->addMinutes(20)]);
        $user->update(['follow_up_enabled' => false]);
        Http::assertNothingSent();

        $this->app->terminate();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://gate.test/next'
            && $request->hasHeader('Authorization', 'Bearer test-cron-secret')
            && $request['next_wake_at'] === now()->addMinutes(20)->getTimestamp()
            && is_int($request['computed_at_ms']));
    }

    public function test_unrelated_user_changes_are_not_pushed(): void
    {
        Http::fake();
        $user = $this->user();

        $user->update(['last_interaction_at' => now()]);
        $this->app->terminate();

        Http::assertNothingSent();
    }

    public function test_nothing_is_pushed_without_a_gate_url(): void
    {
        config(['services.wake_gate.url' => null]);
        Http::fake();

        $this->user()->update(['next_morning_at' => now()->addMinutes(20)]);
        $this->app->terminate();

        Http::assertNothingSent();
    }

    public function test_cron_tick_reports_in_its_response_instead_of_pushing(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 55]]),
            'https://gate.test/*' => Http::response(['ok' => true]),
        ]);
        $this->user(['next_morning_at' => now()->subMinute(), 'morning_time' => '12:00:00']);

        $response = $this->postJson('/internal/cron', [], ['Authorization' => 'Bearer test-cron-secret'])->assertOk();
        $this->app->terminate();

        $this->assertNotNull($response->json('next_wake_at'));
        Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://gate.test'));
    }

    public function test_failed_push_is_logged_and_swallowed(): void
    {
        Http::fake(['https://gate.test/*' => Http::response('down', 503)]);

        $this->user()->update(['next_morning_at' => now()->addMinutes(20)]);
        app(WakeGate::class)->flush();

        Http::assertSentCount(1);
    }
}
