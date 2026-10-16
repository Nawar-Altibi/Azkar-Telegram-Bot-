<?php

namespace App\Models;

use App\Enums\AdhkarMode;
use App\Enums\OnboardingState;
use App\Services\ReminderScheduleCalculator;
use App\Services\WakeGate;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    protected $attributes = [
        'reminder_style' => 'direct',
        'follow_up_enabled' => true,
        'weekly_summary_enabled' => true,
    ];

    protected $fillable = [
        'telegram_id',
        'chat_id',
        'first_name',
        'username',
        'language_code',
        'timezone',
        'timezone_label',
        'morning_time',
        'evening_time',
        'mode',
        'mode_chosen_at',
        'reminders_enabled',
        'keyboard_persistent',
        'reminder_style',
        'follow_up_enabled',
        'weekly_summary_enabled',
        'last_weekly_summary_at',
        'time_fit_asked_at',
        'comeback_sent_at',
        'feedback_nudge_at',
        'onboarding_state',
        'awaiting_input',
        'next_morning_at',
        'next_evening_at',
        'paused_at',
        'last_interaction_at',
    ];

    protected function casts(): array
    {
        return [
            'telegram_id' => 'integer',
            'chat_id' => 'integer',
            'reminders_enabled' => 'boolean',
            'keyboard_persistent' => 'boolean',
            'follow_up_enabled' => 'boolean',
            'weekly_summary_enabled' => 'boolean',
            'mode' => AdhkarMode::class,
            'mode_chosen_at' => 'datetime',
            'onboarding_state' => OnboardingState::class,
            'next_morning_at' => 'datetime',
            'next_evening_at' => 'datetime',
            'paused_at' => 'datetime',
            'last_interaction_at' => 'datetime',
            'last_weekly_summary_at' => 'datetime',
            'time_fit_asked_at' => 'datetime',
            'comeback_sent_at' => 'datetime',
            'feedback_nudge_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (User $user) {
            if ($user->wasChanged(['next_morning_at', 'next_evening_at', 'reminders_enabled', 'follow_up_enabled'])) {
                app(WakeGate::class)->scheduleChanged();
            }
        });
    }

    public function reminderLogs(): HasMany
    {
        return $this->hasMany(ReminderLog::class);
    }

    public function botEvents(): HasMany
    {
        return $this->hasMany(BotEvent::class);
    }

    public function sendsReadingWithReminder(): bool
    {
        return $this->reminder_style === 'direct';
    }

    /**
     * Short or full from the user's last reading, or null before the first choice.
     */
    public function rememberedMode(): ?AdhkarMode
    {
        return $this->mode_chosen_at !== null ? ($this->mode ?? AdhkarMode::Short) : null;
    }

    public function isOnboarded(): bool
    {
        return $this->onboarding_state === OnboardingState::Completed
            && filled($this->timezone)
            && filled($this->morning_time)
            && filled($this->evening_time);
    }

    public function formattedMorningTime(): ?string
    {
        return $this->formatStoredTime($this->morning_time);
    }

    public function formattedEveningTime(): ?string
    {
        return $this->formatStoredTime($this->evening_time);
    }

    private function formatStoredTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = is_string($value) ? $value : (string) $value;

        return ReminderScheduleCalculator::format12Hour($raw);
    }
}
