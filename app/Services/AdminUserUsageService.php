<?php

namespace App\Services;

use App\Models\ReadingSession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Admin-only view of one user's reading over the last week.
 */
class AdminUserUsageService
{
    public function __construct(private readonly AdhkarContentService $content) {}

    /**
     * @return list<string>
     */
    public function report(string $username): array
    {
        $username = ltrim(trim($username), '@');
        if ($username === '') {
            return [__('bot.admin_user_missing')];
        }

        $user = User::query()
            ->whereRaw('lower(username) = ?', [mb_strtolower($username)])
            ->first();
        if ($user === null) {
            return [__('bot.admin_user_not_found', ['username' => e($username)])];
        }

        $timezone = $user->timezone ?: 'UTC';
        $since = now()->subDays(7);
        $sessions = ReadingSession::query()
            ->where('user_id', $user->id)
            ->where('started_at', '>=', $since)
            ->where('source', '!=', 'continue')
            ->orderBy('started_at')
            ->get();

        $lines = [
            __('bot.admin_user_header', [
                'username' => e($user->username ?: $username),
                'timezone' => e(str_replace('_', ' ', (string) last(explode('/', $timezone)))),
            ]),
        ];

        for ($offset = 6; $offset >= 0; $offset--) {
            $day = now($timezone)->subDays($offset)->startOfDay();
            $dateKey = $day->toDateString();
            $lines[] = '';
            $lines[] = '<b>'.$day->translatedFormat('Y-m-d l').'</b>';

            $ofDay = $sessions->filter(function (ReadingSession $session) use ($timezone, $dateKey) {
                try {
                    return $session->started_at->timezone($timezone)->toDateString() === $dateKey;
                } catch (\Throwable) {
                    return false;
                }
            });

            if ($ofDay->isEmpty()) {
                $lines[] = __('bot.admin_user_empty_day');

                continue;
            }

            foreach ($ofDay as $session) {
                $lines[] = __('bot.admin_user_session', [
                    'title' => e($this->content->collectionHeading($session->collection_key)),
                    'furthest' => $session->furthest,
                    'total' => $session->total,
                    'completed' => $session->completed_at !== null ? __('bot.admin_user_completed') : '',
                ]);
                foreach ($this->cardLines($session, $timezone) as $cardLine) {
                    $lines[] = $cardLine;
                }
            }
        }

        return $this->chunks(implode("\n", $lines));
    }

    /**
     * @return list<string>
     */
    private function cardLines(ReadingSession $session, string $timezone): array
    {
        $times = $session->card_times;
        if (! is_array($times) || $times === []) {
            return [];
        }

        ksort($times, SORT_NUMERIC);
        $lines = [];
        foreach ($times as $number => $iso) {
            try {
                $time = Carbon::parse((string) $iso)->timezone($timezone)->format('g:i A');
            } catch (\Throwable) {
                continue;
            }
            $lines[] = __('bot.admin_user_card', [
                'n' => (int) $number,
                'time' => e(ReminderScheduleCalculator::format12Hour(
                    Carbon::parse((string) $iso)->timezone($timezone)->format('H:i')
                ) ?: $time),
            ]);
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function chunks(string $text): array
    {
        if (mb_strlen($text) <= 3500) {
            return [$text];
        }

        $parts = [];
        $current = '';
        foreach (explode("\n", $text) as $line) {
            $candidate = $current === '' ? $line : $current."\n".$line;
            if (mb_strlen($candidate) > 3500 && $current !== '') {
                $parts[] = $current;
                $current = $line;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts !== [] ? $parts : [Str::limit($text, 3500)];
    }
}
