<?php

namespace App\Services;

use App\Enums\AdhkarMode;
use App\Enums\ReminderLogStatus;
use App\Enums\ReminderType;
use App\Jobs\SendAdhkarReminderJob;
use App\Models\ReminderLog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReminderDispatchService
{
    public function __construct(
        private readonly AdhkarContentService $content,
    ) {}

    public function claimDue(): int
    {
        $claimed = 0;
        $now = now();

        User::query()
            ->where('reminders_enabled', true)
            ->where(function ($query) use ($now) {
                $query->where('next_morning_at', '<=', $now)
                    ->orWhere('next_evening_at', '<=', $now);
            })
            ->orderBy('id')
            ->chunkById((int) config('adhkar.chunk_size', 100), function ($users) use ($now, &$claimed) {
                foreach ($users as $user) {
                    if ($user->next_morning_at && $user->next_morning_at->lte($now)) {
                        if ($this->claimOne($user->fresh(), ReminderType::Morning)) {
                            $claimed++;
                        }
                    }

                    $user = $user->fresh();
                    if ($user && $user->next_evening_at && $user->next_evening_at->lte($now)) {
                        if ($this->claimOne($user, ReminderType::Evening)) {
                            $claimed++;
                        }
                    }
                }
            });

        return $claimed;
    }

    public function claimOne(User $user, ReminderType $type): bool
    {
        $dueAt = $type === ReminderType::Morning ? $user->next_morning_at : $user->next_evening_at;
        if ($dueAt === null || ! $user->timezone) {
            return false;
        }

        $localDate = $dueAt->copy()->timezone($user->timezone)->toDateString();
        $localTime = $type === ReminderType::Morning ? $user->morning_time : $user->evening_time;
        if (! $localTime) {
            return false;
        }

        try {
            $log = DB::transaction(function () use ($user, $type, $dueAt, $localDate, $localTime) {
                $log = ReminderLog::query()->create([
                    'user_id' => $user->id,
                    'reminder_type' => $type,
                    'collection_key' => $this->content->collectionKey($user->mode ?? AdhkarMode::Short, $type),
                    'scheduled_local_date' => $localDate,
                    'scheduled_for' => $dueAt,
                    'status' => ReminderLogStatus::Pending,
                ]);

                $next = ReminderScheduleCalculator::calculateNext(
                    $user->timezone,
                    (string) $localTime,
                    $dueAt,
                );

                if ($type === ReminderType::Morning) {
                    $user->next_morning_at = $next;
                } else {
                    $user->next_evening_at = $next;
                }
                $user->save();

                return $log;
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                return false;
            }

            throw $exception;
        }

        $this->dispatchJob($log->id);

        return true;
    }

    public function recoverStalePending(): int
    {
        $threshold = now()->subMinutes((int) config('adhkar.reminder_stale_minutes', 3));
        $maxAttempts = (int) config('adhkar.reminder_dispatch_max_attempts', 5);
        $recovered = 0;

        ReminderLog::query()
            ->where('status', ReminderLogStatus::Pending)
            ->where('created_at', '<=', $threshold)
            ->where('dispatch_attempts', '<', $maxAttempts)
            ->orderBy('id')
            ->each(function (ReminderLog $log) use ($maxAttempts, &$recovered) {
                $log->increment('dispatch_attempts');
                $log->refresh();

                if ($log->dispatch_attempts >= $maxAttempts) {
                    $log->update([
                        'status' => ReminderLogStatus::Failed,
                        'error_message' => 'dispatch_exhausted_retries',
                    ]);

                    return;
                }

                $this->dispatchJob($log->id);
                $recovered++;
            });

        return $recovered;
    }

    private function dispatchJob(int $logId): void
    {
        try {
            SendAdhkarReminderJob::dispatch($logId);
        } catch (Throwable $exception) {
            Log::channel('telegram')->error('Failed to dispatch reminder job', [
                'reminder_log_id' => $logId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) $exception->getCode();
        $message = $exception->getMessage();

        return $sqlState === '23000'
            || str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry');
    }
}
