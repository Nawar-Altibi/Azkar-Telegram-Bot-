<?php

namespace Tests\Feature;

use App\Enums\ReminderLogStatus;
use App\Enums\ReminderType;
use App\Enums\TelegramUpdateStatus;
use App\Models\ReminderLog;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\ContentSyncService;
use App\Services\CronTickService;
use App\Services\ReminderDispatchService;
use App\Services\StaleRecoveryService;
use App\Services\Telegram\TelegramUpdateProcessor;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class SchedulerCronTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Production runs the queue synchronously inside the cron request.
        config(['queue.default' => 'sync']);
        app(ContentSyncService::class)->sync();
        $this->travelTo(Carbon::parse('2026-10-01 12:02:30'));
        // Recovery ran just now unless a test says otherwise.
        Cache::forever(StaleRecoveryService::MARKER_KEY, now()->getTimestamp());
    }

    private function cron()
    {
        return $this->postJson('/internal/cron', [], ['Authorization' => 'Bearer test-cron-secret']);
    }

    private function fakeTelegramOk(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 55]], 200),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function dueUser(array $overrides = []): User
    {
        return User::factory()->create($overrides + [
            'timezone' => 'UTC',
            'morning_time' => '12:00:00',
            'evening_time' => '18:00:00',
            'next_morning_at' => now()->subMinutes(2),
            'next_evening_at' => now()->addHours(6),
        ]);
    }

    public function test_missing_secret_returns_403(): void
    {
        $this->postJson('/internal/cron')->assertStatus(403);
    }

    public function test_wrong_secret_returns_403(): void
    {
        $this->postJson('/internal/cron', [], [
            'Authorization' => 'Bearer wrong-secret',
        ])->assertStatus(403);
    }

    public function test_empty_configured_secret_is_rejected(): void
    {
        config(['services.cron.secret' => '']);

        $this->postJson('/internal/cron', [], [
            'Authorization' => 'Bearer test-cron-secret',
        ])->assertStatus(403);
    }

    public function test_idle_tick_returns_minimal_response_without_work(): void
    {
        Http::fake();
        User::factory()->create();

        $response = $this->cron()->assertOk();

        $this->assertSame(['ok' => true], $response->json());
        Http::assertNothingSent();
        $this->assertSame(0, ReminderLog::query()->count());
        $this->assertNotNull(Cache::get('adhkar:last_scheduler_tick'));
    }

    public function test_tick_runs_in_process_without_scheduler_or_artisan_commands(): void
    {
        $this->fakeTelegramOk();
        $this->dueUser();
        Cache::forget(StaleRecoveryService::MARKER_KEY);
        $started = [];
        Event::listen([ScheduledTaskStarting::class, CommandStarting::class], function ($event) use (&$started) {
            $started[] = $event::class;
        });

        $this->cron()->assertOk();

        $this->assertSame([], $started);
        Http::assertSentCount(1);
    }

    public function test_due_morning_reminder_is_sent_and_rescheduled(): void
    {
        $this->fakeTelegramOk();
        $user = $this->dueUser();

        $this->cron()->assertOk();

        $log = ReminderLog::query()->sole();
        $this->assertSame(ReminderType::Morning, $log->reminder_type);
        $this->assertSame(ReminderLogStatus::Sent, $log->status);
        $this->assertSame([55], $log->telegram_message_id);
        $this->assertSame('2026-10-02 12:00:00', $user->fresh()->next_morning_at->toDateTimeString());
        Http::assertSentCount(1);
    }

    public function test_due_evening_reminder_is_sent_and_rescheduled(): void
    {
        $this->fakeTelegramOk();
        $user = $this->dueUser([
            'evening_time' => '12:00:00',
            'next_morning_at' => now()->addHours(10),
            'next_evening_at' => now()->subMinute(),
        ]);

        $this->cron()->assertOk();

        $log = ReminderLog::query()->sole();
        $this->assertSame(ReminderType::Evening, $log->reminder_type);
        $this->assertSame('evening_short', $log->collection_key);
        $this->assertSame(ReminderLogStatus::Sent, $log->status);
        $this->assertSame('2026-10-02 12:00:00', $user->fresh()->next_evening_at->toDateTimeString());
    }

    public function test_custom_minute_reminder_is_sent_on_the_first_tick_at_or_after_it(): void
    {
        $this->fakeTelegramOk();
        $user = $this->dueUser([
            'morning_time' => '12:03:00',
            'next_morning_at' => Carbon::parse('2026-10-01 12:03:00'),
        ]);

        $this->cron()->assertOk();
        Http::assertNothingSent();

        $this->travelTo(Carbon::parse('2026-10-01 12:03:05'));
        $this->cron()->assertOk();

        Http::assertSentCount(1);
        $this->assertSame('2026-10-02 12:03:00', $user->fresh()->next_morning_at->toDateTimeString());
    }

    public function test_disabled_and_not_due_users_are_skipped(): void
    {
        Http::fake();
        $this->dueUser(['reminders_enabled' => false]);
        $this->dueUser(['next_morning_at' => now()->addMinute()]);

        $this->cron()->assertOk();

        Http::assertNothingSent();
        $this->assertSame(0, ReminderLog::query()->count());
    }

    public function test_back_to_back_ticks_send_only_once(): void
    {
        $this->fakeTelegramOk();
        $user = $this->dueUser();

        $this->cron()->assertOk();
        $afterFirst = $user->fresh()->next_morning_at;
        $this->cron()->assertOk();

        Http::assertSentCount(1);
        $this->assertSame(1, ReminderLog::query()->count());
        $this->assertTrue($afterFirst->equalTo($user->fresh()->next_morning_at));
    }

    public function test_held_tick_lock_skips_overlapping_tick(): void
    {
        $this->fakeTelegramOk();
        $user = $this->dueUser();
        $other = Cache::lock(CronTickService::LOCK_KEY, CronTickService::LOCK_SECONDS);
        $this->assertTrue($other->get());

        $this->cron()->assertOk()->assertExactJson(['ok' => true]);

        Http::assertNothingSent();
        $this->assertSame(0, ReminderLog::query()->count());
        $this->assertTrue($user->fresh()->next_morning_at->lte(now()));

        $other->release();
        $this->cron()->assertOk();
        Http::assertSentCount(1);
    }

    public function test_telegram_failure_marks_log_failed_and_is_not_resent_on_retry(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Internal Server Error'], 500),
        ]);
        $user = $this->dueUser();

        $this->cron()->assertOk();
        $log = ReminderLog::query()->sole();
        $this->assertSame(ReminderLogStatus::Failed, $log->status);
        $this->assertSame('Internal Server Error', $log->error_message);
        $advanced = $user->fresh()->next_morning_at;
        $this->assertSame('2026-10-02 12:00:00', $advanced->toDateTimeString());

        // cron-job.org retry, and a later recovery pass, must not resend or re-advance.
        Cache::forget(StaleRecoveryService::MARKER_KEY);
        $this->travel(10)->minutes();
        $this->cron()->assertOk();

        Http::assertSentCount(1);
        $this->assertSame(1, ReminderLog::query()->count());
        $this->assertTrue($advanced->equalTo($user->fresh()->next_morning_at));
    }

    public function test_claim_interrupted_before_send_is_recovered_once(): void
    {
        $this->fakeTelegramOk();
        $user = $this->dueUser(['next_morning_at' => now()->addDay()]);
        // A tick that committed the claim but died before dispatching the send.
        $log = ReminderLog::factory()->create([
            'user_id' => $user->id,
            'collection_key' => 'morning_short',
            'status' => ReminderLogStatus::Pending,
            'dispatch_attempts' => 0,
        ]);
        $log->forceFill(['created_at' => now()->subMinutes(10)])->save();
        Cache::forget(StaleRecoveryService::MARKER_KEY);

        $this->cron()->assertOk();
        $this->assertSame(ReminderLogStatus::Sent, $log->fresh()->status);

        $this->travel(1)->minutes();
        $this->cron()->assertOk();
        Http::assertSentCount(1);
    }

    public function test_tick_failure_returns_500_without_leaking_details_and_releases_the_lock(): void
    {
        Log::spy();
        $this->mock(ReminderDispatchService::class)
            ->shouldReceive('claimDueLogIds')
            ->once()
            ->andThrow(new RuntimeException('cURL error for https://api.telegram.org/bottest-token/sendMessage'));

        $response = $this->cron()->assertStatus(500);

        $this->assertSame(['ok' => false], $response->json());
        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context) {
            return $message === 'cron_tick_failed'
                && $context['exception'] === RuntimeException::class
                && ! str_contains((string) json_encode($context), 'test-token');
        })->once();
        $this->assertTrue(Cache::lock(CronTickService::LOCK_KEY, 1)->get());
    }

    public function test_claim_budget_defers_remaining_users_to_the_next_tick(): void
    {
        $this->fakeTelegramOk();
        $user = $this->dueUser();
        config(['adhkar.cron_claim_budget_seconds' => 0]);

        $this->cron()->assertOk();
        Http::assertNothingSent();
        $this->assertTrue($user->fresh()->next_morning_at->lte(now()));

        config(['adhkar.cron_claim_budget_seconds' => 25]);
        $this->cron()->assertOk();
        Http::assertSentCount(1);
    }

    public function test_tick_emits_one_compact_metrics_event(): void
    {
        $this->fakeTelegramOk();
        $this->dueUser();
        Log::spy();

        $this->cron()->assertOk();

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) {
            return $message === 'cron_tick'
                && $context['due_count'] === 1
                && $context['sent_count'] === 1
                && $context['failed_count'] === 0
                && $context['telegram_calls'] === 1
                && $context['stale_recovery_ran'] === false
                && $context['skipped_locked'] === false
                && is_int($context['db_queries'])
                && is_int($context['duration_ms'])
                && collect($context)->every(fn ($value) => is_scalar($value) || $value === null);
        })->once();
    }

    public function test_idle_tick_query_budget_with_the_production_database_cache(): void
    {
        Http::fake();
        User::factory()->create();
        config(['cache.default' => 'database']);
        Cache::forever(StaleRecoveryService::MARKER_KEY, now()->getTimestamp());
        $statements = [];
        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$statements) {
            $statements[] = $query->sql;
        });

        $this->cron()->assertOk();

        // tick lock insert, broadcast lookup, due-user select, last-tick upsert, recovery marker read, lock owner check + delete.
        $this->assertCount(7, $statements, implode("\n", $statements));
    }

    public function test_stale_recovery_runs_by_elapsed_time_not_clock_boundary(): void
    {
        Http::fake();
        $this->mock(TelegramUpdateProcessor::class)->shouldReceive('handle');
        Cache::forget(StaleRecoveryService::MARKER_KEY);
        $stuck = fn () => TelegramUpdate::factory()->create([
            'status' => TelegramUpdateStatus::Pending,
            'attempts' => 0,
            'received_at' => now()->subMinutes(20),
        ]);

        // 12:02:30 — no marker yet, so the first tick recovers even off a :05 boundary.
        $first = $stuck();
        $this->cron()->assertOk();
        $this->assertSame(1, $first->fresh()->attempts);

        // 12:03:30 — inside the interval.
        $this->travelTo(Carbon::parse('2026-10-01 12:03:30'));
        $second = $stuck();
        $this->cron()->assertOk();
        $this->assertSame(0, $second->fresh()->attempts);

        // 12:05:00 boundary hit but only 2.5 minutes elapsed: still not due.
        $this->travelTo(Carbon::parse('2026-10-01 12:05:00'));
        $this->cron()->assertOk();
        $this->assertSame(0, $second->fresh()->attempts);

        // 12:07:31 — the 12:05 and 12:06 ticks were "missed"; five minutes elapsed, so it runs.
        $this->travelTo(Carbon::parse('2026-10-01 12:07:31'));
        $this->cron()->assertOk();
        $this->assertSame(1, $second->fresh()->attempts);

        // A long outage: the first tick afterwards recovers immediately.
        $this->travelTo(Carbon::parse('2026-10-01 12:30:00'));
        $third = $stuck();
        $this->cron()->assertOk();
        $this->assertSame(1, $third->fresh()->attempts);
    }
}
