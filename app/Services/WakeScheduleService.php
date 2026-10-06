<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The earliest moment a cron tick has work to do, so the wake gate can let the container sleep until then.
 */
class WakeScheduleService
{
    public function __construct(
        private readonly StaleRecoveryService $recovery,
        private readonly FollowUpReminderService $followUps,
        private readonly GroupPostService $groupPosts,
    ) {}

    /**
     * Null when nothing is scheduled at all; a past time means "tick as soon as possible".
     */
    public function nextWakeAt(): ?Carbon
    {
        $times = [];

        $row = User::query()
            ->where('reminders_enabled', true)
            ->toBase()
            ->selectRaw('min(next_morning_at) as morning, min(next_evening_at) as evening')
            ->first();
        foreach ([$row->morning ?? null, $row->evening ?? null] as $value) {
            if ($value !== null) {
                $times[] = Carbon::parse($value);
            }
        }

        $groupPostAt = $this->groupPosts->nextDueAt();
        if ($groupPostAt !== null) {
            $times[] = $groupPostAt;
        }

        // Follow-ups and recovery only run on recovery's own cadence, so they cannot wake it earlier than that.
        $recoveryAt = null;
        $followUpAt = $this->followUps->nextDueAt();
        if ($followUpAt !== null) {
            $recoveryAt = $this->recovery->nextRunAt();
            $times[] = $followUpAt->max($recoveryAt);
        }
        if ($this->recovery->hasPendingWork()) {
            $times[] = $recoveryAt ?? $this->recovery->nextRunAt();
        }

        return $times === [] ? null : collect($times)->min();
    }
}
