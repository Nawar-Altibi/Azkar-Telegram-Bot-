<?php

namespace App\Models;

use App\Enums\AdhkarMode;
use App\Services\ReminderScheduleCalculator;
use App\Services\WakeGate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class GroupChat extends Model
{
    protected $attributes = [
        'morning_time' => '06:00',
        'evening_time' => '17:00',
        'mode' => 'short',
        'enabled' => false,
        'bot_member' => true,
    ];

    protected $fillable = [
        'chat_id',
        'title',
        'timezone',
        'morning_time',
        'evening_time',
        'mode',
        'enabled',
        'bot_member',
        'next_morning_at',
        'next_evening_at',
    ];

    protected function casts(): array
    {
        return [
            'chat_id' => 'integer',
            'mode' => AdhkarMode::class,
            'enabled' => 'boolean',
            'bot_member' => 'boolean',
            'next_morning_at' => 'datetime',
            'next_evening_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (GroupChat $group) {
            if ($group->wasChanged(['next_morning_at', 'next_evening_at', 'enabled', 'bot_member'])) {
                app(WakeGate::class)->scheduleChanged();
            }
        });
    }

    /**
     * Groups whose daily posts are on and that the bot can still post to.
     *
     * @param  Builder<GroupChat>  $query
     */
    public function scopePosting(Builder $query): void
    {
        $query->where('enabled', true)->where('bot_member', true);
    }

    public function isPosting(): bool
    {
        return $this->enabled && $this->bot_member && filled($this->timezone);
    }

    /**
     * Recomputes both post times from now, or clears them when posting is off.
     */
    public function reschedule(): void
    {
        if (! $this->isPosting()) {
            $this->next_morning_at = null;
            $this->next_evening_at = null;

            return;
        }

        $this->next_morning_at = ReminderScheduleCalculator::calculateNext($this->timezone, $this->morning_time);
        $this->next_evening_at = ReminderScheduleCalculator::calculateNext($this->timezone, $this->evening_time);
    }
}
