<?php

namespace App\Services;

use App\Enums\ReminderLogStatus;
use App\Enums\ReminderType;
use App\Models\ReadingSession;
use App\Models\ReminderLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Finishing morning or evening from the buttons counts the same as pressing تم on today's reminder.
 */
class ReminderCompletionService
{
    public function completeToday(User $user, ReminderType $type): void
    {
        $today = $this->today($user);
        if ($today === null) {
            return;
        }

        $log = ReminderLog::query()
            ->where('user_id', $user->id)
            ->where('reminder_type', $type)
            ->whereDate('scheduled_local_date', $today)
            ->where('status', ReminderLogStatus::Sent)
            ->whereNull('completed_at')
            ->latest('id')
            ->first();

        if ($log === null) {
            return;
        }

        $log->update([
            'status' => ReminderLogStatus::Completed,
            'completed_at' => now(),
            'reading_started_at' => $log->reading_started_at ?? now(),
        ]);
    }

    public function alreadyFinished(User $user, ReminderType $type): bool
    {
        $bounds = $this->localDay($user);
        if ($bounds === null) {
            return false;
        }

        [$from, $until] = $bounds;
        $prefix = $type === ReminderType::Evening ? 'evening' : 'morning';

        return ReadingSession::query()
            ->where('user_id', $user->id)
            ->whereIn('collection_key', [$prefix.'_short', $prefix.'_full'])
            ->whereNotNull('completed_at')
            ->where('last_seen_at', '>=', $from)
            ->where('last_seen_at', '<', $until)
            ->exists();
    }

    private function today(User $user): ?string
    {
        if (! $user->timezone) {
            return null;
        }

        try {
            return now()->timezone($user->timezone)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function localDay(User $user): ?array
    {
        $today = $this->today($user);
        if ($today === null) {
            return null;
        }

        try {
            $start = Carbon::parse($today, $user->timezone)->startOfDay();
        } catch (Throwable) {
            return null;
        }

        return [$start->copy()->utc(), $start->copy()->addDay()->utc()];
    }
}
