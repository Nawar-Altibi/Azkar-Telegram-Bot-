<?php

namespace App\Services;

use App\Enums\ReminderLogStatus;
use App\Enums\TelegramUpdateStatus;
use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\ReminderLog;
use App\Models\TelegramUpdate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class StaleRecoveryService
{
    public const MARKER_KEY = 'adhkar:last_stale_recovery_at';

    public const LOCK_KEY = 'adhkar:stale-recovery';

    public const INTERVAL_MINUTES = 5;

    // Bounded so a crashed run cannot block recovery for long; a run is far shorter.
    public const LOCK_SECONDS = 120;

    public function __construct(
        private readonly ReminderDispatchService $dispatcher,
    ) {}

    /**
     * Runs recovery when the shared marker is missing or older than the interval.
     * Elapsed-time based so a delayed or missed cron tick cannot skip a cycle.
     *
     * @return array{updates:int, reminders:int}|null null when recovery was not due or another instance holds it
     */
    public function runIfDue(): ?array
    {
        if (! $this->isDue()) {
            return null;
        }

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);
        if (! $lock->get()) {
            return null;
        }

        try {
            if (! $this->isDue()) {
                return null;
            }

            $result = $this->recover();
            Cache::forever(self::MARKER_KEY, now()->getTimestamp());

            return $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{updates:int, reminders:int}
     */
    public function recover(): array
    {
        return [
            'updates' => $this->recoverTelegramUpdates(),
            'reminders' => $this->dispatcher->recoverStalePending(),
        ];
    }

    public function nextRunAt(): Carbon
    {
        $last = Cache::get(self::MARKER_KEY);

        return is_numeric($last)
            ? Carbon::createFromTimestamp((int) $last)->addMinutes(self::INTERVAL_MINUTES)
            : now();
    }

    /**
     * Whether a recovery run could still change something: an unconfirmed reminder or an unprocessed update.
     */
    public function hasPendingWork(): bool
    {
        return ReminderLog::query()->where('status', ReminderLogStatus::Pending)->exists()
            || TelegramUpdate::query()
                ->whereIn('status', [TelegramUpdateStatus::Pending, TelegramUpdateStatus::Processing])
                ->where('attempts', '<', (int) config('adhkar.update_max_attempts', 5))
                ->exists();
    }

    public function isDue(): bool
    {
        $last = Cache::get(self::MARKER_KEY);
        if (! is_numeric($last)) {
            return true;
        }

        return Carbon::createFromTimestamp((int) $last)->lte(now()->subMinutes(self::INTERVAL_MINUTES));
    }

    private function recoverTelegramUpdates(): int
    {
        $threshold = now()->subMinutes((int) config('adhkar.update_stale_minutes', 5));
        $maxAttempts = (int) config('adhkar.update_max_attempts', 5);
        $recovered = 0;

        TelegramUpdate::query()
            ->whereIn('status', [TelegramUpdateStatus::Pending, TelegramUpdateStatus::Processing])
            ->where('received_at', '<=', $threshold)
            ->where('attempts', '<', $maxAttempts)
            ->orderBy('id')
            ->each(function (TelegramUpdate $update) use ($maxAttempts, &$recovered) {
                $update->increment('attempts');
                $update->refresh();

                if ($update->attempts >= $maxAttempts) {
                    $update->update([
                        'status' => TelegramUpdateStatus::Failed,
                        'error_message' => 'dispatch_exhausted_retries',
                    ]);

                    return;
                }

                ProcessTelegramUpdateJob::dispatch($update->id);
                $recovered++;
            });

        return $recovered;
    }
}
