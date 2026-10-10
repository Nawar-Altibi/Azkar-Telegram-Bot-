<?php

namespace App\Services;

use App\Enums\AdhkarMode;
use App\Enums\ReminderLogStatus;
use App\Enums\ReminderType;
use App\Jobs\SendAdhkarReminderJob;
use App\Models\ReminderLog;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
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
        return count($this->claimDueLogIds());
    }

    public function hasDue(): bool
    {
        $now = now();

        return User::query()
            ->where('reminders_enabled', true)
            ->where(function ($query) use ($now) {
                $query->where('next_morning_at', '<=', $now)
                    ->orWhere('next_evening_at', '<=', $now);
            })
            ->exists();
    }

    /**
     * Claims due reminders and dispatches their send jobs.
     *
     * @param  float|null  $deadline  hrtime seconds after which no new user is claimed; unclaimed users stay due for the next tick
     * @return list<int> ids of the reminder logs claimed by this call
     */
    public function claimDueLogIds(?float $deadline = null): array
    {
        $claimed = [];
        $now = now();

        User::query()
            ->select(['id', 'next_morning_at', 'next_evening_at'])
            ->where('reminders_enabled', true)
            ->where(function ($query) use ($now) {
                $query->where('next_morning_at', '<=', $now)
                    ->orWhere('next_evening_at', '<=', $now);
            })
            ->orderBy('id')
            ->chunkById((int) config('adhkar.chunk_size', 100), function ($users) use ($now, $deadline, &$claimed) {
                foreach ($users as $candidate) {
                    if ($deadline !== null && hrtime(true) / 1e9 >= $deadline) {
                        return false;
                    }

                    $morningDue = $candidate->next_morning_at && $candidate->next_morning_at->lte($now);

                    // The chunk row may be stale by the time it is processed; claim from the current row.
                    $user = $candidate->fresh();

                    if ($morningDue && $this->isStillDue($user, ReminderType::Morning, $now)) {
                        $logId = $this->claim($user, ReminderType::Morning);
                        if ($logId !== null) {
                            $claimed[] = $logId;
                            // The sync send may have disabled a blocked user; never save over that state.
                            $user = $user->fresh();
                        }
                    }

                    if ($this->isStillDue($user, ReminderType::Evening, $now)) {
                        $logId = $this->claim($user, ReminderType::Evening);
                        if ($logId !== null) {
                            $claimed[] = $logId;
                        }
                    }
                }
            });

        return $claimed;
    }

    public function claimOne(User $user, ReminderType $type): bool
    {
        return $this->claim($user, $type) !== null;
    }

    private function isStillDue(?User $user, ReminderType $type, DateTimeInterface $now): bool
    {
        if ($user === null || ! $user->reminders_enabled) {
            return false;
        }

        $dueAt = $type === ReminderType::Morning ? $user->next_morning_at : $user->next_evening_at;

        return $dueAt !== null && $dueAt->lte($now);
    }

    private function claim(User $user, ReminderType $type): ?int
    {
        $dueAt = $type === ReminderType::Morning ? $user->next_morning_at : $user->next_evening_at;
        if ($dueAt === null || ! $user->timezone) {
            return null;
        }

        $localDate = $dueAt->copy()->timezone($user->timezone)->toDateString();
        $localTime = $type === ReminderType::Morning ? $user->morning_time : $user->evening_time;
        if (! $localTime) {
            return null;
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

                // Schedule from now, not from $dueAt, so a long outage yields one late reminder instead of one per missed day.
                $this->advanceNext($user, $type, (string) $localTime, $dueAt);

                return $log;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->resolveExistingClaim($user, $type, $dueAt, $localDate, (string) $localTime);
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                return $this->resolveExistingClaim($user, $type, $dueAt, $localDate, (string) $localTime);
            }

            throw $exception;
        }

        $this->dispatchJob($log->id);

        return $log->id;
    }

    /**
     * A unique day key means this slot was already claimed.
     * Pending → re-dispatch. Terminal while still due (e.g. user moved the time later today) →
     * reuse the row for a fresh send, then advance next_*_at so the user cannot stay stuck.
     */
    private function resolveExistingClaim(
        User $user,
        ReminderType $type,
        DateTimeInterface $dueAt,
        string $localDate,
        string $localTime,
    ): ?int {
        $existing = ReminderLog::query()
            ->where('user_id', $user->id)
            ->where('reminder_type', $type)
            ->whereDate('scheduled_local_date', $localDate)
            ->first();

        $fresh = User::query()->find($user->id);
        if ($fresh === null || ! $this->isStillDue($fresh, $type, now())) {
            if ($existing !== null && $existing->status === ReminderLogStatus::Pending) {
                $this->dispatchJob($existing->id);

                return $existing->id;
            }

            return null;
        }

        $this->advanceNext($fresh, $type, $localTime, $dueAt);

        if ($existing === null) {
            return null;
        }

        if ($existing->status !== ReminderLogStatus::Pending) {
            $existing->update([
                'scheduled_for' => $dueAt,
                'status' => ReminderLogStatus::Pending,
                'collection_key' => $this->content->collectionKey($fresh->mode ?? AdhkarMode::Short, $type),
                'telegram_message_id' => null,
                'sent_at' => null,
                'reading_started_at' => null,
                'follow_up_sent_at' => null,
                'completed_at' => null,
                'error_message' => null,
                'retry_count' => 0,
                'dispatch_attempts' => 0,
                'last_dispatch_attempt_at' => null,
                'content_version_map' => null,
                'content_snapshot_hash' => null,
            ]);
        }

        $this->dispatchJob($existing->id);

        return $existing->id;
    }

    private function advanceNext(User $user, ReminderType $type, string $localTime, DateTimeInterface $dueAt): void
    {
        $after = $dueAt instanceof Carbon ? $dueAt->copy() : Carbon::parse($dueAt);
        $next = ReminderScheduleCalculator::calculateNext(
            $user->timezone,
            $localTime,
            $after->max(now()),
        );

        if ($type === ReminderType::Morning) {
            $user->next_morning_at = $next;
        } else {
            $user->next_evening_at = $next;
        }
        $user->save();
    }

    public function recoverStalePending(): int
    {
        $threshold = now()->subMinutes((int) config('adhkar.reminder_stale_minutes', 3));
        $maxAttempts = (int) config('adhkar.reminder_dispatch_max_attempts', 5);
        $recovered = 0;

        ReminderLog::query()
            ->where('status', ReminderLogStatus::Pending)
            ->where(function ($query) use ($threshold) {
                $query->where(function ($inner) use ($threshold) {
                    $inner->whereNull('last_dispatch_attempt_at')
                        ->where('created_at', '<=', $threshold);
                })->orWhere(function ($inner) use ($threshold) {
                    $inner->whereNotNull('last_dispatch_attempt_at')
                        ->where('last_dispatch_attempt_at', '<=', $threshold);
                });
            })
            ->orderBy('id')
            ->each(function (ReminderLog $log) use ($maxAttempts, &$recovered) {
                if ($log->last_dispatch_attempt_at !== null) {
                    $log->update([
                        'status' => ReminderLogStatus::Failed,
                        'error_message' => 'nudge_unconfirmed',
                    ]);

                    return;
                }

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
                'error' => TelegramClient::redact($exception->getMessage()),
            ]);
        }
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) $exception->getCode();
        $message = $exception->getMessage();

        return $sqlState === '23000'
            || $sqlState === '23505'
            || str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry')
            || str_contains($message, 'duplicate key');
    }
}
