<?php

namespace App\Services;

use App\Enums\ReminderLogStatus;
use App\Models\ReminderLog;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * One cron-job.org tick, executed in the current request without the Laravel scheduler or a subprocess.
 */
class CronTickService
{
    public const LOCK_KEY = 'adhkar:cron-tick';

    // A tick is bounded by the claim budget plus one Telegram timeout (~40s) and nginx's 60s upstream
    // timeout; 120s keeps ticks from overlapping while a crashed tick blocks the next ones for at most 2 minutes.
    public const LOCK_SECONDS = 120;

    private bool $recording = false;

    private int $queries = 0;

    private int $telegramCalls = 0;

    public function __construct(
        private readonly ReminderDispatchService $dispatcher,
        private readonly StaleRecoveryService $recovery,
        private readonly FollowUpReminderService $followUps,
    ) {}

    /**
     * @return array<string, int|bool>
     */
    public function run(): array
    {
        $started = hrtime(true) / 1e9;
        $deadline = $started + (float) config('adhkar.cron_claim_budget_seconds', 25);
        $this->startRecording();

        $metrics = [
            'due_count' => 0,
            'sent_count' => 0,
            'failed_count' => 0,
            'stale_recovery_ran' => false,
            'stale_reminders_recovered' => 0,
            'stale_updates_recovered' => 0,
            'follow_ups_sent' => 0,
            'skipped_locked' => false,
            'ok' => false,
        ];

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);
        $acquired = false;

        try {
            $acquired = (bool) $lock->get();
            if (! $acquired) {
                $metrics['skipped_locked'] = true;
                $metrics['ok'] = true;

                return $metrics;
            }

            $logIds = $this->dispatcher->claimDueLogIds($deadline);
            $metrics['due_count'] = count($logIds);
            if ($logIds !== []) {
                $metrics = $this->outcomes($logIds) + $metrics;
            }

            Cache::put('adhkar:last_scheduler_tick', now()->toIso8601String(), now()->addHour());

            // Out of budget: the shared marker stays untouched, so the next tick runs recovery.
            if (hrtime(true) / 1e9 < $deadline) {
                $recovered = $this->recovery->runIfDue();
                if ($recovered !== null) {
                    $metrics['stale_recovery_ran'] = true;
                    $metrics['stale_reminders_recovered'] = $recovered['reminders'];
                    $metrics['stale_updates_recovered'] = $recovered['updates'];
                    // Rides on recovery's 5-minute cadence so idle ticks stay within their query budget.
                    $metrics['follow_ups_sent'] = $this->followUps->sendDue();
                }
            }

            if (config('queue.default') !== 'sync') {
                Artisan::call('queue:work', [
                    '--stop-when-empty' => true,
                    '--sleep' => 1,
                    '--max-time' => 20,
                    '--tries' => 3,
                    '--no-interaction' => true,
                ]);
            }

            $metrics['ok'] = true;

            return $metrics;
        } finally {
            if ($acquired) {
                $lock->release();
            }

            $this->recording = false;
            Log::info('cron_tick', [
                'duration_ms' => (int) round((hrtime(true) / 1e9 - $started) * 1000),
                'telegram_calls' => $this->telegramCalls,
                'db_queries' => config('adhkar.cron_count_queries', true) ? $this->queries : null,
            ] + $metrics);
        }
    }

    /**
     * @param  list<int>  $logIds
     * @return array{sent_count:int, failed_count:int}
     */
    private function outcomes(array $logIds): array
    {
        $counts = ReminderLog::query()
            ->whereIn('id', $logIds)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'sent_count' => (int) ($counts[ReminderLogStatus::Sent->value] ?? 0),
            'failed_count' => (int) ($counts[ReminderLogStatus::Failed->value] ?? 0),
        ];
    }

    private function startRecording(): void
    {
        $this->recording = true;
        $this->queries = 0;
        $this->telegramCalls = 0;

        if (config('adhkar.cron_count_queries', true)) {
            Event::listen(QueryExecuted::class, function () {
                if ($this->recording) {
                    $this->queries++;
                }
            });
        }

        Event::listen([ResponseReceived::class, ConnectionFailed::class], function () {
            if ($this->recording) {
                $this->telegramCalls++;
            }
        });
    }
}
