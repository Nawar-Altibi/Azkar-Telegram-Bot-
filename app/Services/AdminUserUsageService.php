<?php

namespace App\Services;

use App\Models\AdhkarCollection;
use App\Models\ReadingSession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Admin-only view of one user's reading over the last week.
 */
class AdminUserUsageService
{
    /** @var array<string, list<string>> */
    private array $labelsByCollection = [];

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
            return [__('bot.admin_user_no_card_times')];
        }

        ksort($times, SORT_NUMERIC);
        $labels = $this->labelsFor($session->collection_key);
        $lines = [];
        foreach ($times as $number => $iso) {
            $n = (int) $number;
            if ($n < 1) {
                continue;
            }

            $clock = $this->clockLabel((string) $iso, $timezone);
            if ($clock === null) {
                continue;
            }

            $lines[] = __('bot.admin_user_card', [
                'n' => $n,
                'title' => e($labels[$n - 1] ?? __('bot.admin_user_card_fallback', ['n' => $n])),
                'time' => e($clock),
            ]);
        }

        return $lines !== [] ? $lines : [__('bot.admin_user_no_card_times')];
    }

    private function clockLabel(string $iso, string $timezone): ?string
    {
        try {
            $local = Carbon::parse($iso)->timezone($timezone);
        } catch (\Throwable) {
            return null;
        }

        $formatted = ReminderScheduleCalculator::format12Hour($local->format('H:i'));

        return $formatted !== '' ? $formatted : $local->format('g:i A');
    }

    /**
     * @return list<string>
     */
    private function labelsFor(string $collectionKey): array
    {
        if (isset($this->labelsByCollection[$collectionKey])) {
            return $this->labelsByCollection[$collectionKey];
        }

        $collection = AdhkarCollection::query()
            ->where('key', $collectionKey)
            ->where('active', true)
            ->first();
        if ($collection === null) {
            return $this->labelsByCollection[$collectionKey] = [];
        }

        $labels = [];
        foreach ($collection->items()->where('adhkar_items.active', true)->get() as $item) {
            $title = trim((string) ($item->title ?: ''));
            if ($title === '') {
                $title = Str::limit(preg_replace('/\s+/u', ' ', (string) $item->arabic_text) ?? '', 36, '…');
            }
            $labels[] = $title !== '' ? $title : 'الذكر';
        }

        return $this->labelsByCollection[$collectionKey] = $labels;
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
