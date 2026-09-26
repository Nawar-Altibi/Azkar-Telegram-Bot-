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

        if ($user->onboarding_state === OnboardingState::New) {
            return $this->tutorialScreen();
        }

        return $this->screenForState($user);
    }

    /**
     * @return array{text:string,reply_markup:?array}
     */
    public function beginSetup(User $user): array
    {
        if ($user->onboarding_state === OnboardingState::Completed) {
            return [
                'text' => __('bot.welcome_returning', ['summary' => $this->summary($user)]),
                'reply_markup' => $this->keyboards->returningStart(),
            ];
        }

        if ($user->onboarding_state === OnboardingState::New) {
            $user->onboarding_state = OnboardingState::WaitingTimezone;
            $user->awaiting_input = null;
            $user->save();
        }

        return $this->screenForState($user);
    }

    /**
     * Reopens the guide without restarting setup.
     *
     * @return array{text:string,reply_markup:?array}
     */
    public function explanation(User $user): array
    {
        if ($user->onboarding_state === OnboardingState::New) {
            return $this->tutorialScreen();
        }

        return [
            'text' => __('bot.explain', ['body' => __('bot.explain_body')]),
            'reply_markup' => $user->isOnboarded() ? $this->keyboards->mainReplyKeyboard($user) : null,
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

        return $this->timezoneScreen();
    }

    /**
     * @return array{text:string,reply_markup:?array}
     */
    public function handleTimezone(User $user, string $timezone, ?string $label = null): array
    {
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException('invalid_timezone');
        }

        $user->timezone = $timezone;
        $user->timezone_label = $label ?: TimezoneCatalog::labelForTimezone($timezone) ?: __('bot.timezone_fallback');
        $user->onboarding_state = OnboardingState::WaitingMorningTime;
        $user->awaiting_input = null;
        $user->save();

        return [
            'text' => __('bot.ask_morning_time'),
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
            'text' => __('bot.ask_evening_time'),
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
            'text' => __('bot.ask_mode'),
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

    /**
     * @return array{id: string, label: string}|null
     */
    public function resolveTimezoneInput(string $text): ?array
    {
        return TimezoneCatalog::resolveWithLabel($text);
    }

    /**
     * @return array{text:string,reply_markup:?array}
     */
    public function back(User $user): array
    {
        $awaiting = (string) $user->awaiting_input;

        if ($awaiting !== '') {
            $user->awaiting_input = null;
            $user->save();

            if (str_contains($awaiting, 'timezone')) {
                return $this->timezoneScreen();
            }
            if (str_contains($awaiting, 'morning')) {
                return $this->morningScreen();
            }
            if (str_contains($awaiting, 'evening')) {
                return $this->eveningScreen();
            }
        }

        if ($user->onboarding_state === OnboardingState::Completed) {
            return $this->settingsScreen($user);
        }

        return match ($user->onboarding_state) {
            OnboardingState::WaitingMode => $this->rewind($user, OnboardingState::WaitingEveningTime, $this->eveningScreen()),
            OnboardingState::WaitingEveningTime => $this->rewind($user, OnboardingState::WaitingMorningTime, $this->morningScreen()),
            default => $this->rewind($user, OnboardingState::WaitingTimezone, $this->timezoneScreen()),
        };
    }

    /**
     * @return array{text:string,reply_markup:?array}
     */
    public function cancel(User $user): array
    {
        $user->awaiting_input = null;
        $user->save();

        if ($user->onboarding_state === OnboardingState::Completed) {
            return $this->settingsScreen($user);
        }

        return $this->screenForState($user);
    }

    public function summary(User $user): string
    {
        $mode = $user->mode === AdhkarMode::Full ? __('bot.mode_full') : __('bot.mode_short');
        $reminders = $user->reminders_enabled ? __('bot.reminders_on') : __('bot.reminders_off');
        $region = $user->timezone_label
            ?: TimezoneCatalog::labelForTimezone($user->timezone)
            ?: __('bot.timezone_fallback');

        return implode("\n", [
            __('bot.summary_timezone', ['timezone' => $region]),
            __('bot.summary_morning', ['time' => $user->formattedMorningTime() ?: '—']),
            __('bot.summary_evening', ['time' => $user->formattedEveningTime() ?: '—']),
            __('bot.summary_mode', ['mode' => $mode]),
            __('bot.summary_reminders', ['status' => $reminders]),
        ]);
    }

    /**
     * @return array{text:string,reply_markup:?array}
     */
    public function settingsScreen(User $user): array
    {
        return [
            'text' => __('bot.settings_header')."\n\n".$this->summary($user),
            'reply_markup' => $this->keyboards->settingsMenu($user),
        ];
    }

    /**
     * @param  array{text:string,reply_markup:?array}  $screen
     * @return array{text:string,reply_markup:?array}
     */
    private function rewind(User $user, OnboardingState $state, array $screen): array
    {
        $user->onboarding_state = $state;
        $user->awaiting_input = null;
        $user->save();

        return $screen;
    }

    /**
     * @return array{text:string,reply_markup:?array}
     */
    private function screenForState(User $user): array
    {
        return match ($user->onboarding_state) {
            OnboardingState::WaitingMorningTime => $this->morningScreen(),
            OnboardingState::WaitingEveningTime => $this->eveningScreen(),
            OnboardingState::WaitingMode => [
                'text' => __('bot.ask_mode'),
                'reply_markup' => $this->keyboards->modeOptions(),
            ],
            OnboardingState::Completed => $this->settingsScreen($user),
            default => $this->timezoneScreen(),
        };
    }

    /**
     * @return array{text:string,reply_markup:?array}
     */
    private function tutorialScreen(): array
    {
        return [
            'text' => __('bot.tutorial', ['body' => __('bot.explain_body')]),
            'reply_markup' => $this->keyboards->tutorialStart(),
        ];
    }

    /**
     * @return array{text:string,reply_markup:?array}
     */
    private function timezoneScreen(): array
    {
        return [
            'text' => __('bot.ask_timezone'),
            'reply_markup' => $this->keyboards->timezoneOptions(false),
        ];
    }

    /**
     * @return array{text:string,reply_markup:?array}
     */
    private function morningScreen(): array
    {
        return [
            'text' => __('bot.ask_morning_time'),
            'reply_markup' => $this->keyboards->timePresets('morning'),
        ];
    }

    /**
     * @return array{text:string,reply_markup:?array}
     */
    private function eveningScreen(): array
    {
        return [
            'text' => __('bot.ask_evening_time'),
            'reply_markup' => $this->keyboards->timePresets('evening'),
        ];
    }
}
