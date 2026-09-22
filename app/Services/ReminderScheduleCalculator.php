<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use InvalidArgumentException;

class ReminderScheduleCalculator
{
    public static function calculateNext(string $timezone, string $localTime, ?CarbonInterface $after = null): Carbon
    {
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException("Invalid timezone: {$timezone}");
        }

        $normalized = self::normalizeTime($localTime);
        $afterUtc = Carbon::parse($after ?? now())->utc();
        $localNow = $afterUtc->copy()->setTimezone($timezone);
        $candidate = Carbon::parse($localNow->toDateString().' '.$normalized, $timezone);

        if ($candidate->lte($afterUtc)) {
            $candidate->addDay();
        }

        return $candidate->utc();
    }

    public static function normalizeTime(string $localTime): string
    {
        $localTime = trim($localTime);

        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $localTime, $matches) !== 1) {
            throw new InvalidArgumentException("Invalid time: {$localTime}");
        }

        return sprintf('%02d:%02d:%02d', (int) $matches[1], (int) $matches[2], (int) ($matches[3] ?? 0));
    }

    public static function isValidTime(string $localTime): bool
    {
        try {
            self::normalizeTime($localTime);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
