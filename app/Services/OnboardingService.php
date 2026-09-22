<?php

namespace App\Services;

use App\Enums\AdhkarMode;
use App\Enums\OnboardingState;
use App\Models\User;
use App\Support\Telegram\KeyboardFactory;
use App\Support\TimezoneCatalog;
use InvalidArgumentException;

class OnboardingService
{
    public function __construct(
        private readonly UserSettingsService $settings,
        private readonly KeyboardFactory $keyboards,
    ) {}

    /**
     * @return array{text:string,reply_markup:?array}
     */
    public function start(User $user): array
    {
        if ($user->onboarding_state === OnboardingState::Completed) {
            return [
                'text' => __('bot.welcome_returning', ['summary' => $this->summary($user)]),
                'reply_markup' => $this->keyboards->returningStart(),
            ];
        }

        $user->onboarding_state = OnboardingState::WaitingTimezone;
        $user->awaiting_input = null;
        $user->save();

        return [
            'text' => __('bot.welcome')."\n\n".__('bot.ask_timezone'),
            'reply_markup' => $this->keyboards->timezoneOptions(),
        ];
    }

    /**
     * @return array{text:string,reply_markup:?array}
     */
    public function reset(User $user): array
    {
        $user->onboarding_state = OnboardingState::WaitingTimezone;
        $user->awaiting_input = null;
        $user->reminders_enabled = false;
        $user->save();

        return [
            'text' => __('bot.ask_timezone'),
            'reply_markup' => $this->keyboards->timezoneOptions(),
        ];
    }

    /**
     * @return array{text:string,reply_markup:?array}
     */
    public function handleTimezone(User $user, string $timezone): array
    {
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException('invalid_timezone');
        }

        $user->timezone = $timezone;
        $user->onboarding_state = OnboardingState::WaitingMorningTime;
        $user->awaiting_input = null;
        $user->save();

        return [
            'text' => __('bot.timezone_saved', ['timezone' => $timezone])."\n\n".__('bot.ask_morning_time'),
            'reply_markup' => $this->keyboards->timePresets('morning'),
        ];
    }

    /**
     * @return array{text:string,reply_markup:?array}
     */
    public function handleMorningTime(User $user, string $time): array
    {
        $user->morning_time = ReminderScheduleCalculator::normalizeTime($time);
        $user->onboarding_state = OnboardingState::WaitingEveningTime;
        $user->awaiting_input = null;
        $user->save();

        return [
            'text' => __('bot.morning_saved', ['time' => substr((string) $user->morning_time, 0, 5)])."\n\n".__('bot.ask_evening_time'),
            'reply_markup' => $this->keyboards->timePresets('evening'),
        ];
    }

    /**
     * @return array{text:string,reply_markup:?array}
     */
    public function handleEveningTime(User $user, string $time): array
    {
        $user->evening_time = ReminderScheduleCalculator::normalizeTime($time);
        $user->onboarding_state = OnboardingState::WaitingMode;
        $user->awaiting_input = null;
        $user->save();

        return [
            'text' => __('bot.evening_saved', ['time' => substr((string) $user->evening_time, 0, 5)])."\n\n".__('bot.ask_mode'),
            'reply_markup' => $this->keyboards->modeOptions(),
        ];
    }

    /**
     * @return array{text:string,reply_markup:?array}
     */
    public function handleMode(User $user, AdhkarMode $mode): array
    {
        $this->settings->completeOnboarding($user, $mode);

        return [
            'text' => __('bot.onboarding_complete', ['summary' => $this->summary($user->fresh())]),
            'reply_markup' => $this->keyboards->mainReplyKeyboard($user->fresh()),
        ];
    }

    public function resolveTimezoneInput(string $text): ?string
    {
        return TimezoneCatalog::resolve($text);
    }

    public function summary(User $user): string
    {
        $mode = $user->mode === AdhkarMode::Full ? __('bot.mode_full') : __('bot.mode_short');
        $reminders = $user->reminders_enabled ? __('bot.reminders_on') : __('bot.reminders_off');

        return implode("\n", [
            __('bot.summary_timezone', ['timezone' => $user->timezone ?: '—']),
            __('bot.summary_morning', ['time' => $user->formattedMorningTime() ?: '—']),
            __('bot.summary_evening', ['time' => $user->formattedEveningTime() ?: '—']),
            __('bot.summary_mode', ['mode' => $mode]),
            __('bot.summary_reminders', ['status' => $reminders]),
        ]);
    }
}
