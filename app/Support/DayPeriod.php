<?php

namespace App\Support;

use App\Models\User;
use Throwable;

final class DayPeriod
{
    /**
     * morning, evening, night, or null for the hours between them.
     */
    public static function forUser(User $user): ?string
    {
        if (! $user->timezone) {
            return null;
        }

        try {
            $hour = (int) now()->timezone($user->timezone)->format('G');
        } catch (Throwable) {
            return null;
        }

        return self::forHour($hour);
    }

    public static function forHour(int $hour): ?string
    {
        foreach (config('adhkar.day_windows', []) as $period => [$from, $to]) {
            $inside = $from <= $to
                ? $hour >= $from && $hour < $to
                : $hour >= $from || $hour < $to;
            if ($inside) {
                return $period;
            }
        }

        return null;
    }
}
