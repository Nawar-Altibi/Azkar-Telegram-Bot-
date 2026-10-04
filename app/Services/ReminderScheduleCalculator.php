<?php

namespace App\Services;

use App\Exceptions\InvalidTimeException;
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
        $hour12 = $hour % 12;
        if ($hour12 === 0) {
            $hour12 = 12;
        }

        return $hour12.':'.$minute.' '.self::periodWord($hour);
    }

    /**
     * The part of the day people say aloud: 4:00 عصرًا rather than 4:00 م.
     */
    public static function periodWord(int $hour): string
    {
        return match (true) {
            $hour < 4 => 'ليلًا',
            $hour < 12 => 'صباحًا',
            $hour < 15 => 'ظهرًا',
            $hour < 18 => 'عصرًا',
            default => 'مساءً',
        };
    }

    /**
     * @return array{hour:int,minute:int,second:int,suffix:?string}
     */
    private static function parseTimeParts(string $localTime): array
    {
        $localTime = self::toAsciiDigits(trim($localTime));
        if ($localTime === '') {
            throw new InvalidTimeException(InvalidTimeException::EMPTY);
        }
        $localTime = str_replace(['.', '٫'], ':', $localTime);
        $localTime = preg_replace(['/[\x{064B}-\x{0652}\x{0640}]/u', '/\s+/u'], ['', ' '], $localTime) ?? $localTime;

        ['text' => $localTime, 'suffix' => $suffix, 'fraction' => $fraction] = self::extractSpokenParts($localTime);
        if ($localTime === '' && ($suffix !== null || $fraction !== null)) {
            throw new InvalidTimeException(InvalidTimeException::LETTERS);
        }

        if (preg_match('/^([01]?\d|2[0-3])(?::([0-5]\d)(?::([0-5]\d))?)?$/', $localTime, $matches) !== 1) {
            throw self::diagnose($localTime, $suffix);
        }

        $hour = (int) $matches[1];
        $minute = (int) ($matches[2] ?? 0);
        if ($fraction !== null && ! isset($matches[2])) {
            $minute = $fraction['minute'];
            if ($fraction['before']) {
                $hour = $hour === 0 ? 23 : $hour - 1;
            }
        }

        return [
            'hour' => $hour,
            'minute' => $minute,
            'second' => (int) ($matches[3] ?? 0),
            'suffix' => $suffix,
        ];
    }

    /**
     * Pulls the spoken parts out of a typed time: الصبح/المسا and similar words, ونص/وربع/إلا ربع,
     * and the word الساعة. What remains should be digits only.
     *
     * @return array{text:string,suffix:?string,fraction:?array{minute:int,before:bool}}
     */
    private static function extractSpokenParts(string $value): array
    {
        $value = preg_replace('/(?:^|\s)(?:ال)?ساعة(?=\s|$)/u', ' ', $value) ?? $value;

        $fraction = null;
        if (preg_match('/(?:^|\s)(?:إلا|الا|إلّا)\s*(ربع|الربع|ثلث|الثلث)(?=\s|$)/u', $value, $match) === 1) {
            $fraction = ['minute' => str_contains($match[1], 'ربع') ? 45 : 40, 'before' => true];
            $value = str_replace($match[0], ' ', $value);
        } elseif (preg_match('/(?:^|\s)و\s*(نص|نصف|النص|ربع|الربع|ثلث|الثلث)(?=\s|$)/u', $value, $match) === 1) {
            $fraction = [
                'minute' => match (true) {
                    str_contains($match[1], 'ربع') => 15,
                    str_contains($match[1], 'ثلث') => 20,
                    default => 30,
                },
                'before' => false,
            ];
            $value = str_replace($match[0], ' ', $value);
        }

        $suffix = null;
        $kept = [];
        foreach (preg_split('/\s+/u', trim($value)) ?: [] as $token) {
            $period = self::periodForWord($token);
            if ($period !== null && $suffix === null) {
                $suffix = $period;

                continue;
            }
            $kept[] = $token;
        }
        $value = implode(' ', $kept);

        // A suffix written against the digits, as in 8م or 8:30ص.
        if ($suffix === null && preg_match('/^(.*\d)\s*(ص|م|am|pm)$/iu', $value, $match) === 1) {
            $suffix = self::suffixPeriod($match[2]);
            $value = $match[1];
        }

        return ['text' => trim($value), 'suffix' => $suffix, 'fraction' => $fraction];
    }

    private static function periodForWord(string $token): ?string
    {
        $token = mb_strtolower(trim($token));
        $candidates = [$token, preg_replace('/ا$/u', '', $token) ?? $token];

        $morning = ['ص', 'am', 'صباح', 'الصباح', 'صبح', 'الصبح', 'بالصبح', 'فجر', 'الفجر'];
        $evening = [
            'م', 'pm', 'مساء', 'المساء', 'مسا', 'المسا', 'بالمسا', 'ليل', 'الليل', 'بالليل', 'ليلا',
            'عصر', 'العصر', 'بالعصر', 'مغرب', 'المغرب', 'عشا', 'العشا', 'العشاء', 'ضهر', 'الضهر', 'ظهر', 'الظهر', 'بالضهر',
        ];

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $morning, true)) {
                return 'am';
            }
            if (in_array($candidate, $evening, true)) {
                return 'pm';
            }
        }

        return null;
    }

    /**
     * Explains why a value that failed the strict pattern is not a time.
     */
    private static function diagnose(string $value, ?string $suffix): InvalidTimeException
    {
        if ($value === '') {
            return new InvalidTimeException(InvalidTimeException::EMPTY);
        }

        if (preg_match('/\p{L}/u', $value) === 1) {
            return new InvalidTimeException(InvalidTimeException::LETTERS);
        }

        if (preg_match('/^(\d+)\s*(?:[;,\-\/_،؛\\\\|]+|\s+)\s*(\d+)$/u', $value, $parts) === 1) {
            return new InvalidTimeException(
                InvalidTimeException::BAD_SEPARATOR,
                self::suggestion($parts[1], $parts[2], $suffix),
            );
        }

        if (preg_match('/^\d+$/', $value) === 1) {
            if (strlen($value) <= 2) {
                return new InvalidTimeException(InvalidTimeException::HOUR_RANGE);
            }
            if (strlen($value) <= 4) {
                $suggestion = self::suggestion(substr($value, 0, -2), substr($value, -2), $suffix);
                if ($suggestion !== null) {
                    return new InvalidTimeException(InvalidTimeException::BAD_SEPARATOR, $suggestion);
                }
            }

            return new InvalidTimeException(InvalidTimeException::FORMAT);
        }

        if (preg_match('/^(\d+):(\d*)(?::\d*)?$/', $value, $parts) === 1) {
            if (strlen($parts[1]) > 2 || (int) $parts[1] > 23) {
                return new InvalidTimeException(InvalidTimeException::HOUR_RANGE);
            }
            if ($parts[2] === '') {
                return new InvalidTimeException(
                    InvalidTimeException::MISSING_MINUTES,
                    sprintf('%02d:00', (int) $parts[1]),
                );
            }
            if (strlen($parts[2]) !== 2) {
                $suggestion = strlen($parts[2]) === 1
                    ? sprintf('%02d:0%d', (int) $parts[1], (int) $parts[2])
                    : null;

                return new InvalidTimeException(InvalidTimeException::MINUTE_DIGITS, $suggestion);
            }

            return new InvalidTimeException(InvalidTimeException::MINUTE_RANGE);
        }

        return new InvalidTimeException(InvalidTimeException::FORMAT);
    }

    private static function suggestion(string $hour, string $minute, ?string $suffix): ?string
    {
        if (strlen($hour) > 2 || strlen($minute) !== 2) {
            return null;
        }

        $h = (int) $hour;
        $m = (int) $minute;
        if ($m > 59 || $h > 23 || ($suffix !== null && $h > 12)) {
            return null;
        }

        if ($suffix === 'pm' && $h < 12) {
            $h += 12;
        } elseif ($suffix === 'am' && $h === 12) {
            $h = 0;
        }

        return sprintf('%02d:%02d', $h, $m);
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
            throw new InvalidTimeException(InvalidTimeException::HOUR_RANGE);
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
