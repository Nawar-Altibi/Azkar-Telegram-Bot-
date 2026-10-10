<?php

namespace Tests\Feature;

use App\Enums\AdhkarMode;
use App\Enums\ReminderLogStatus;
use App\Enums\ReminderType;
use App\Jobs\SendAdhkarReminderJob;
use App\Models\ReminderLog;
use App\Models\User;
use App\Services\ContentSyncService;
use App\Services\ReminderDispatchService;
use App\Services\StaleRecoveryService;
use App\Services\UserSettingsService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReminderDispatchTest extends TestCase
{
    public function test_duplicate_scheduler_execution_creates_one_log_and_one_job(): void
    {
        Bus::fake([SendAdhkarReminderJob::class]);
        $user = User::factory()->create([
            'reminders_enabled' => true,
            'mode' => AdhkarMode::Short,
            'timezone' => 'Asia/Damascus',
            'morning_time' => '06:00:00',
            'next_morning_at' => now()->subMinute(),
            'next_evening_at' => now()->addHours(6),
        ]);
        $originalNextMorning = $user->next_morning_at->copy();

        $this->artisan('adhkar:dispatch-due-reminders')->assertSuccessful();
        $this->artisan('adhkar:dispatch-due-reminders')->assertSuccessful();

        $this->assertSame(1, ReminderLog::query()->where('user_id', $user->id)->count());
        Bus::assertDispatchedTimes(SendAdhkarReminderJob::class, 1);
        $this->assertTrue($user->fresh()->next_morning_at->gt($originalNextMorning));
    }

    public function test_long_outage_sends_one_late_reminder_instead_of_one_per_missed_day(): void
    {
        Bus::fake([SendAdhkarReminderJob::class]);
        $user = User::factory()->create([
            'reminders_enabled' => true,
            'mode' => AdhkarMode::Short,
            'timezone' => 'Asia/Damascus',
            'morning_time' => '06:00:00',
            'next_morning_at' => now()->subDays(10),
            'next_evening_at' => now()->addHours(6),
        ]);

        $this->artisan('adhkar:dispatch-due-reminders')->assertSuccessful();
        $this->artisan('adhkar:dispatch-due-reminders')->assertSuccessful();

        $this->assertSame(1, ReminderLog::query()->where('user_id', $user->id)->count());
        Bus::assertDispatchedTimes(SendAdhkarReminderJob::class, 1);
        $this->assertTrue($user->fresh()->next_morning_at->isFuture());
    }

    public function test_unique_constraint_with_pending_log_redispatches_and_advances_next_time(): void
    {
        Bus::fake([SendAdhkarReminderJob::class]);
        $user = User::factory()->create([
            'reminders_enabled' => true,
            'timezone' => 'UTC',
            'morning_time' => '06:00:00',
            'next_morning_at' => now()->subMinute(),
            'next_evening_at' => now()->addHours(8),
        ]);

        $log = ReminderLog::factory()->create([
            'user_id' => $user->id,
            'reminder_type' => ReminderType::Morning,
            'scheduled_local_date' => $user->next_morning_at->timezone('UTC')->toDateString(),
            'scheduled_for' => $user->next_morning_at,
            'status' => ReminderLogStatus::Pending,
        ]);

        $before = $user->next_morning_at->copy();
        $claimed = app(ReminderDispatchService::class)->claimOne($user->fresh(), ReminderType::Morning);

        $this->assertTrue($claimed);
        $this->assertTrue($user->fresh()->next_morning_at->gt($before));
        $this->assertSame(1, ReminderLog::query()->where('user_id', $user->id)->count());
        Bus::assertDispatched(SendAdhkarReminderJob::class, fn (SendAdhkarReminderJob $job) => $job->reminderLogId === $log->id);
    }

    public function test_unique_constraint_with_already_sent_log_resends_when_time_was_moved_later(): void
    {
        Bus::fake([SendAdhkarReminderJob::class]);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-10 18:30:00'));
        $user = User::factory()->create([
            'reminders_enabled' => true,
            'timezone' => 'Asia/Damascus',
            'morning_time' => '08:33:00',
            'evening_time' => '21:04:00',
            'next_morning_at' => now()->addDay(),
            'next_evening_at' => now()->subMinutes(26),
        ]);

        $log = ReminderLog::factory()->create([
            'user_id' => $user->id,
            'reminder_type' => ReminderType::Evening,
            'scheduled_local_date' => '2026-10-10',
            'scheduled_for' => now()->subHours(4),
            'status' => ReminderLogStatus::Sent,
            'sent_at' => now()->subHours(4),
        ]);

        $claimed = app(ReminderDispatchService::class)->claimOne($user->fresh(), ReminderType::Evening);

        $this->assertTrue($claimed);
        $log->refresh();
        $this->assertSame(ReminderLogStatus::Pending, $log->status);
        $this->assertNull($log->sent_at);
        $this->assertSame('2026-10-11 18:04:00', $user->fresh()->next_evening_at->toDateTimeString());
        Bus::assertDispatched(SendAdhkarReminderJob::class, fn (SendAdhkarReminderJob $job) => $job->reminderLogId === $log->id);
    }

    public function test_changing_evening_time_after_todays_send_schedules_later_today(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-10 15:00:00'));
        $user = User::factory()->create([
            'reminders_enabled' => true,
            'timezone' => 'Asia/Damascus',
            'morning_time' => '06:00:00',
            'evening_time' => '17:00:00',
            'next_morning_at' => now()->addDay(),
            'next_evening_at' => now()->addDay(),
        ]);

        ReminderLog::factory()->create([
            'user_id' => $user->id,
            'reminder_type' => ReminderType::Evening,
            'scheduled_local_date' => '2026-10-10',
            'scheduled_for' => now()->subHour(),
            'status' => ReminderLogStatus::Sent,
            'sent_at' => now()->subHour(),
        ]);

        app(UserSettingsService::class)->updateEveningTime($user->fresh(), '21:04');

        $this->assertSame('2026-10-10 18:04:00', $user->fresh()->next_evening_at->toDateTimeString());
    }

    public function test_recovery_redispatches_stale_pending_logs_and_exhausts(): void
    {
        Bus::fake([SendAdhkarReminderJob::class]);
        $log = ReminderLog::factory()->create([
            'status' => ReminderLogStatus::Pending,
            'dispatch_attempts' => 0,
            'created_at' => now()->subMinutes(10),
        ]);
        $log->created_at = now()->subMinutes(10);
        $log->save();

        $recovered = app(ReminderDispatchService::class)->recoverStalePending();
        $this->assertSame(1, $recovered);
        $this->assertSame(1, $log->fresh()->dispatch_attempts);
        Bus::assertDispatched(SendAdhkarReminderJob::class);

        $log->update(['dispatch_attempts' => 4, 'created_at' => now()->subMinutes(10)]);
        app(ReminderDispatchService::class)->recoverStalePending();
        $this->assertSame(ReminderLogStatus::Failed, $log->fresh()->status);
        $this->assertSame('dispatch_exhausted_retries', $log->fresh()->error_message);
    }

    public function test_recovery_does_not_resend_after_an_outbound_attempt(): void
    {
        Bus::fake([SendAdhkarReminderJob::class]);
        $log = ReminderLog::factory()->create([
            'status' => ReminderLogStatus::Pending,
            'dispatch_attempts' => 1,
            'last_dispatch_attempt_at' => now()->subMinutes(10),
            'created_at' => now()->subMinutes(20),
        ]);
        $log->created_at = now()->subMinutes(20);
        $log->save();

        $recovered = app(ReminderDispatchService::class)->recoverStalePending();

        $this->assertSame(0, $recovered);
        $this->assertSame(ReminderLogStatus::Failed, $log->fresh()->status);
        $this->assertSame('nudge_unconfirmed', $log->fresh()->error_message);
        Bus::assertNotDispatched(SendAdhkarReminderJob::class);
    }

    public function test_recovery_leaves_an_in_flight_attempt_alone(): void
    {
        Bus::fake([SendAdhkarReminderJob::class]);
        $log = ReminderLog::factory()->create([
            'status' => ReminderLogStatus::Pending,
            'last_dispatch_attempt_at' => now()->subMinute(),
            'created_at' => now()->subMinutes(20),
        ]);
        $log->created_at = now()->subMinutes(20);
        $log->save();

        $recovered = app(ReminderDispatchService::class)->recoverStalePending();

        $this->assertSame(0, $recovered);
        $this->assertSame(ReminderLogStatus::Pending, $log->fresh()->status);
        Bus::assertNotDispatched(SendAdhkarReminderJob::class);
    }

    public function test_disabled_users_are_skipped(): void
    {
        Bus::fake();
        User::factory()->create([
            'reminders_enabled' => false,
            'next_morning_at' => now()->subMinute(),
        ]);

        $this->artisan('adhkar:dispatch-due-reminders')->assertSuccessful();
        Bus::assertNotDispatched(SendAdhkarReminderJob::class);
    }

    public function test_dispatch_command_no_longer_runs_stale_recovery_every_minute(): void
    {
        Bus::fake([SendAdhkarReminderJob::class]);
        $log = ReminderLog::factory()->create(['status' => ReminderLogStatus::Pending, 'dispatch_attempts' => 0]);
        $log->forceFill(['created_at' => now()->subMinutes(10)])->save();

        $this->artisan('adhkar:dispatch-due-reminders')->assertSuccessful();

        $this->assertSame(0, $log->fresh()->dispatch_attempts);
        Bus::assertNotDispatched(SendAdhkarReminderJob::class);
    }

    /**
     * Mutates the row right after the due-user chunk reads it, like a concurrent webhook would.
     */
    private function afterChunkRead(User $user, callable $change): void
    {
        $done = false;
        User::retrieved(function (User $retrieved) use ($user, $change, &$done) {
            if (! $done && $retrieved->id === $user->id) {
                $done = true;
                $change();
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function dueMorning(): array
    {
        return [
            'reminders_enabled' => true,
            'timezone' => 'UTC',
            'morning_time' => '06:00:00',
            'evening_time' => '18:00:00',
            'next_morning_at' => now()->subMinute(),
            'next_evening_at' => now()->addHours(6),
        ];
    }

    public function test_time_moved_later_during_the_tick_is_not_claimed_early(): void
    {
        Bus::fake([SendAdhkarReminderJob::class]);
        $user = User::factory()->create($this->dueMorning());
        $moved = now()->addHours(3)->startOfSecond();
        $this->afterChunkRead($user, fn () => DB::table('users')->where('id', $user->id)->update(['next_morning_at' => $moved]));

        $this->assertSame(0, app(ReminderDispatchService::class)->claimDue());

        $this->assertSame(0, ReminderLog::query()->count());
        $this->assertTrue($user->fresh()->next_morning_at->equalTo($moved));
        Bus::assertNotDispatched(SendAdhkarReminderJob::class);
    }

    public function test_reminders_disabled_during_the_tick_are_not_claimed(): void
    {
        Bus::fake([SendAdhkarReminderJob::class]);
        $user = User::factory()->create($this->dueMorning());
        $this->afterChunkRead($user, fn () => DB::table('users')->where('id', $user->id)->update(['reminders_enabled' => false]));

        $this->assertSame(0, app(ReminderDispatchService::class)->claimDue());
        $this->assertSame(0, ReminderLog::query()->count());
    }

    public function test_user_deleted_during_the_tick_is_skipped(): void
    {
        Bus::fake([SendAdhkarReminderJob::class]);
        $user = User::factory()->create($this->dueMorning());
        $other = User::factory()->create($this->dueMorning());
        $this->afterChunkRead($user, fn () => DB::table('users')->where('id', $user->id)->delete());

        $this->assertSame(1, app(ReminderDispatchService::class)->claimDue());
        $this->assertSame([$other->id], ReminderLog::query()->pluck('user_id')->all());
    }

    public function test_both_slots_due_claims_both_with_correct_next_times(): void
    {
        Bus::fake([SendAdhkarReminderJob::class]);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-01 19:00:00'));
        $user = User::factory()->create(array_merge($this->dueMorning(), [
            'next_morning_at' => now()->subHours(13),
            'next_evening_at' => now()->subHour(),
        ]));

        $ids = app(ReminderDispatchService::class)->claimDueLogIds();

        $this->assertCount(2, $ids);
        $user->refresh();
        $this->assertSame('2026-10-02 06:00:00', $user->next_morning_at->toDateTimeString());
        $this->assertSame('2026-10-02 18:00:00', $user->next_evening_at->toDateTimeString());
        Bus::assertDispatchedTimes(SendAdhkarReminderJob::class, 2);
    }

    public function test_blocked_user_on_morning_is_not_claimed_for_evening(): void
    {
        config(['queue.default' => 'sync']);
        app(ContentSyncService::class)->sync();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Forbidden: bot was blocked by the user'], 403),
        ]);
        $user = User::factory()->create(array_merge($this->dueMorning(), [
            'next_evening_at' => now()->subMinute(),
        ]));

        app(ReminderDispatchService::class)->claimDue();

        Http::assertSentCount(1);
        $this->assertSame(1, ReminderLog::query()->count());
        $user->refresh();
        $this->assertFalse($user->reminders_enabled);
        $this->assertNull($user->next_morning_at);
        $this->assertNull($user->next_evening_at);
        $this->assertSame('blocked_by_user', ReminderLog::query()->sole()->error_message);
    }

    public function test_concurrent_claim_from_a_stale_instance_hits_the_unique_constraint(): void
    {
        Bus::fake([SendAdhkarReminderJob::class]);
        $user = User::factory()->create($this->dueMorning());
        $staleCopy = $user->fresh();

        $this->assertSame(1, app(ReminderDispatchService::class)->claimDue());
        $advanced = $user->fresh()->next_morning_at;

        // A second tick that loaded the row before the first committed: re-dispatch only, do not skip a day.
        $this->assertTrue(app(ReminderDispatchService::class)->claimOne($staleCopy, ReminderType::Morning));
        $this->assertSame(1, ReminderLog::query()->count());
        $this->assertTrue($advanced->equalTo($user->fresh()->next_morning_at));
        Bus::assertDispatched(SendAdhkarReminderJob::class);
    }

    public function test_stale_recovery_service_skips_inside_the_interval_and_under_a_held_lock(): void
    {
        Bus::fake([SendAdhkarReminderJob::class]);
        $recovery = app(StaleRecoveryService::class);

        $this->assertNotNull($recovery->runIfDue());
        $this->assertNull($recovery->runIfDue());

        $this->travel(6)->minutes();
        $other = Cache::lock(StaleRecoveryService::LOCK_KEY, StaleRecoveryService::LOCK_SECONDS);
        $this->assertTrue($other->get());
        $this->assertNull($recovery->runIfDue());

        $other->release();
        $this->assertNotNull($recovery->runIfDue());
    }

    public function test_stale_recovery_failure_leaves_the_marker_for_the_next_tick(): void
    {
        $this->mock(ReminderDispatchService::class)
            ->shouldReceive('recoverStalePending')
            ->once()
            ->andThrow(new \RuntimeException('db down'));

        try {
            app(StaleRecoveryService::class)->runIfDue();
            $this->fail('Expected the failure to propagate.');
        } catch (\RuntimeException) {
        }

        $this->assertNull(Cache::get(StaleRecoveryService::MARKER_KEY));
        $this->assertTrue(Cache::lock(StaleRecoveryService::LOCK_KEY, 1)->get());
    }
}
