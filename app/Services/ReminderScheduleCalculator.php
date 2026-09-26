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
        $parts = self::parseTimeParts($localTime);

        return self::toStored($parts['hour'], $parts['minute'], $parts['second'], $parts['suffix']);
    }

    /**
     * Bare evening hours 1–11 and any bare hour 12 need an explicit ص/م choice.
     * A suffix, or an unambiguous 24-hour value, is saved immediately.
     *
     * @return array{action:'save',time:string}|array{action:'confirm',options:array<int,array{label:string,hm:string,time:string}>}
     */
    public static function classifyTime(string $localTime, string $kind): array
    {
        $parts = self::parseTimeParts($localTime);
        if ($parts['suffix'] !== null) {
            return [
                'action' => 'save',
                'time' => self::toStored($parts['hour'], $parts['minute'], $parts['second'], $parts['suffix']),
            ];
        }

        $hour = $parts['hour'];
        $ambiguous = $hour === 12 || ($kind === 'evening' && $hour >= 1 && $hour <= 11);
        if (! $ambiguous) {
            return [
                'action' => 'save',
                'time' => self::toStored($hour, $parts['minute'], $parts['second'], null),
            ];
        }

        $morningHour = $hour === 12 ? 0 : $hour;
        $eveningHour = $hour === 12 ? 12 : $hour + 12;

        return [
            'action' => 'confirm',
            'options' => [
                self::periodChoice($morningHour, $parts['minute'], $parts['second']),
                self::periodChoice($eveningHour, $parts['minute'], $parts['second']),
            ],
        ];
    }

    public static function format12Hour(string $stored): string
    {
        $stored = self::toAsciiDigits(trim($stored));
        if (preg_match('/^(\d{1,2}):(\d{2})/', $stored, $matches) !== 1) {
            return $stored;
        }

        $hour = (int) $matches[1];
        $minute = $matches[2];
        $period = $hour < 12 ? 'ص' : 'م';
        $hour12 = $hour % 12;
        if ($hour12 === 0) {
            $hour12 = 12;
        }

        return $hour12.':'.$minute.' '.$period;
    }

    /**
     * @return array{hour:int,minute:int,second:int,suffix:?string}
     */
    private static function parseTimeParts(string $localTime): array
    {
        $localTime = self::toAsciiDigits(trim($localTime));
        $localTime = str_replace(['.', '٫'], ':', $localTime);
        $localTime = preg_replace('/\s+/u', ' ', $localTime) ?? $localTime;

        $suffix = null;
        if (preg_match('/(?:\s*)(صباحاً|صباحا|مساءً|مساء|صباح|am|pm|ص|م)\s*$/iu', $localTime, $suffixMatch) === 1) {
            $suffix = self::suffixPeriod($suffixMatch[1]);
            $localTime = trim(mb_substr($localTime, 0, mb_strlen($localTime) - mb_strlen($suffixMatch[1])));
        }

        if (preg_match('/^([01]?\d|2[0-3])(?::([0-5]\d)(?::([0-5]\d))?)?$/', $localTime, $matches) !== 1) {
            throw new InvalidArgumentException('invalid_time');
        }

        $minute = $matches[2] ?? null;
        if ($minute === null && $suffix === null) {
            throw new InvalidArgumentException('invalid_time');
        }

        return [
            'hour' => (int) $matches[1],
            'minute' => (int) ($minute ?? 0),
            'second' => (int) ($matches[3] ?? 0),
            'suffix' => $suffix,
        ];
    }

    private static function suffixPeriod(string $token): string
    {
        $token = mb_strtolower(str_replace('ً', '', $token));

        return in_array($token, ['م', 'مساء', 'pm'], true) ? 'pm' : 'am';
    }

    private static function toStored(int $hour, int $minute, int $second, ?string $suffix): string
    {
        if ($suffix === 'am' && $hour === 12) {
            $hour = 0;
        } elseif ($suffix === 'pm' && $hour < 12) {
            $hour += 12;
        }

        if ($hour > 23) {
            throw new InvalidArgumentException('invalid_time');
        }

        return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
    }

    /**
     * @return array{label:string,hm:string,time:string}
     */
    private static function periodChoice(int $hour, int $minute, int $second): array
    {
        $time = sprintf('%02d:%02d:%02d', $hour, $minute, $second);

        return [
            'label' => self::format12Hour($time),
            'hm' => sprintf('%02d%02d', $hour, $minute),
            'time' => $time,
        ];
    }

    public static function toAsciiDigits(string $value): string
    {
        return strtr($value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
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
