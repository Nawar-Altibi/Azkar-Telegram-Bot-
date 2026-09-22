<?php

namespace App\Services;

use App\Enums\AdhkarMode;
use App\Enums\OnboardingState;
use App\Models\User;
use InvalidArgumentException;

class UserSettingsService
{
    public function updateTimezone(User $user, string $timezone): User
    {
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException('invalid_timezone');
        }

        $user->timezone = $timezone;
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

    public function updateMode(User $user, AdhkarMode $mode): User
    {
        $user->mode = $mode;
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

    public function toggleReminders(User $user): string
    {
        return $user->reminders_enabled ? $this->pause($user) : $this->resume($user);
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

    public function completeOnboarding(User $user, AdhkarMode $mode): User
    {
        $user->mode = $mode;
        $user->onboarding_state = OnboardingState::Completed;
        $user->awaiting_input = null;
        $user->reminders_enabled = true;
        $user->paused_at = null;
        $this->recalculateNextReminders($user);
        $user->save();

        return $user;
    }
}
