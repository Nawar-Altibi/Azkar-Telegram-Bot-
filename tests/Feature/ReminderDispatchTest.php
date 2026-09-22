<?php

namespace Tests\Feature;

use App\Enums\AdhkarMode;
use App\Enums\ReminderLogStatus;
use App\Enums\ReminderType;
use App\Jobs\SendAdhkarReminderJob;
use App\Models\ReminderLog;
use App\Models\User;
use App\Services\ReminderDispatchService;
use Illuminate\Support\Facades\Bus;
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

    public function test_unique_constraint_rollback_does_not_advance_next_time(): void
    {
        $user = User::factory()->create([
            'reminders_enabled' => true,
            'timezone' => 'UTC',
            'morning_time' => '06:00:00',
            'next_morning_at' => now()->subMinute(),
            'next_evening_at' => now()->addHours(8),
        ]);

        ReminderLog::factory()->create([
            'user_id' => $user->id,
            'reminder_type' => ReminderType::Morning,
            'scheduled_local_date' => $user->next_morning_at->timezone('UTC')->toDateString(),
            'scheduled_for' => $user->next_morning_at,
            'status' => ReminderLogStatus::Pending,
        ]);

        $before = $user->next_morning_at->copy();
        $claimed = app(ReminderDispatchService::class)->claimOne($user->fresh(), ReminderType::Morning);

        $this->assertFalse($claimed);
        $this->assertTrue($user->fresh()->next_morning_at->equalTo($before));
        $this->assertSame(1, ReminderLog::query()->where('user_id', $user->id)->count());
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
}
