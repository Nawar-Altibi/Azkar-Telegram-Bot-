<?php

namespace App\Services;

use App\Enums\OnboardingState;
use App\Models\User;
use App\Support\TimezoneCatalog;
use InvalidArgumentException;

class UserSettingsService
{
    public function updateTimezone(User $user, string $timezone, ?string $label = null): User
    {
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException('invalid_timezone');
        }

        $user->timezone = $timezone;
        $user->timezone_label = $label ?: TimezoneCatalog::labelForTimezone($timezone);
        $this->recalculateNextReminders($user);
        $user->save();

        return $user;
    }

    public function updateMorningTime(User $user, string $time): User
    {
        $user->morning_time = ReminderScheduleCalculator::normalizeTime($time);
        $this->recalculateNextReminders($user);
        $user->save();

        return $user;
    }

    public function updateEveningTime(User $user, string $time): User
    {
        $user->evening_time = ReminderScheduleCalculator::normalizeTime($time);
        $this->recalculateNextReminders($user);
        $user->save();

        return $user;
    }

    public function pause(User $user): string
    {
        if (! $user->isOnboarded()) {
            return 'not_onboarded';
        }

        if (! $user->reminders_enabled) {
            return 'already_paused';
        }

        $user->reminders_enabled = false;
        $user->paused_at = now();
        $user->next_morning_at = null;
        $user->next_evening_at = null;
        $user->save();

        return 'paused';
    }

    public function resume(User $user): string
    {
        if (! $user->isOnboarded()) {
            return 'not_onboarded';
        }

        if ($user->reminders_enabled) {
            return 'already_active';
        }

        $user->reminders_enabled = true;
        $user->paused_at = null;
        $this->recalculateNextReminders($user);
        $user->save();

        return 'resumed';
    }

    public function recalculateNextReminders(User $user): void
    {
        if (! $user->timezone || ! $user->morning_time || ! $user->evening_time) {
            return;
        }

        $user->next_morning_at = ReminderScheduleCalculator::calculateNext(
            $user->timezone,
            (string) $user->morning_time,
        );
        $user->next_evening_at = ReminderScheduleCalculator::calculateNext(
            $user->timezone,
            (string) $user->evening_time,
        );
    }

    public function completeOnboarding(User $user): User
    {
        $user->onboarding_state = OnboardingState::Completed;
        $user->awaiting_input = null;
        $user->reminders_enabled = true;
        $user->paused_at = null;
        $user->reminder_style = 'direct';
        $user->follow_up_enabled = true;
        $user->weekly_summary_enabled = true;
        $this->recalculateNextReminders($user);
        $user->save();

        return $user;
    }
}
