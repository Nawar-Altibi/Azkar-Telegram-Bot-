<?php

namespace App\Models;

use App\Enums\AdhkarMode;
use App\Enums\OnboardingState;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    protected $fillable = [
        'telegram_id',
        'chat_id',
        'first_name',
        'username',
        'language_code',
        'timezone',
        'morning_time',
        'evening_time',
        'mode',
        'reminders_enabled',
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
            'mode' => AdhkarMode::class,
            'onboarding_state' => OnboardingState::class,
            'next_morning_at' => 'datetime',
            'next_evening_at' => 'datetime',
            'paused_at' => 'datetime',
            'last_interaction_at' => 'datetime',
        ];
    }

    public function reminderLogs(): HasMany
    {
        return $this->hasMany(ReminderLog::class);
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

        return substr($raw, 0, 5);
    }
}
