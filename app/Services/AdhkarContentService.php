<?php

namespace App\Services;

use App\Enums\AdhkarCategory;
use App\Enums\AdhkarMode;
use App\Enums\ReminderType;
use App\Models\AdhkarCollection;
use App\Models\AdhkarItem;
use App\Models\User;
use Illuminate\Support\Collection;

class AdhkarContentService
{
    /**
     * Manual collections read in one version only, keyed by their period code in rt:/sv:t: callbacks.
     */
    private const SINGLE_COLLECTIONS = ['b' => 'bedtime', 'w' => 'waking', 'p' => 'after_prayer', 'z' => 'additional'];

    public function resolveCollection(User $user, ReminderType $type): ?AdhkarCollection
    {
        return AdhkarCollection::query()
            ->where('key', $this->collectionKey($user->mode ?? AdhkarMode::Short, $type))
            ->where('active', true)
            ->first();
    }

    public function collectionKey(AdhkarMode $mode, ReminderType $type): string
    {
        $variant = $mode === AdhkarMode::Full ? 'full' : 'short';

        return $type->value.'_'.$variant;
    }

    /**
     * Base collection key for a manual reading callback, or null when the period/phase pair is not valid.
     */
    public function manualBaseKey(string $period, string $phase): ?string
    {
        if (isset(self::SINGLE_COLLECTIONS[$period])) {
            return $phase === 'a' ? self::SINGLE_COLLECTIONS[$period] : null;
        }

        $type = match ($period) {
            'm' => ReminderType::Morning,
            'e' => ReminderType::Evening,
            default => null,
        };
        if ($type === null || ! in_array($phase, ['s', 'f', 'a'], true)) {
            return null;
        }

        return $this->collectionKey($phase === 'a' ? AdhkarMode::Full : AdhkarMode::Short, $type);
    }

    public function isSingleVersion(string $collectionKey): bool
    {
        return in_array($collectionKey, self::SINGLE_COLLECTIONS, true);
    }

    /**
     * Plain Arabic title, e.g. "أذكار النوم". Unknown keys get a neutral title, never a morning one.
     */
    public function collectionHeading(string $collectionKey): string
    {
        return match (true) {
            str_starts_with($collectionKey, 'morning') => 'أذكار الصباح',
            str_starts_with($collectionKey, 'evening') => 'أذكار المساء',
            $collectionKey === 'bedtime' => 'أذكار النوم',
            $collectionKey === 'waking' => 'أذكار الاستيقاظ',
            $collectionKey === 'after_prayer' => 'أذكار بعد الصلاة',
            $collectionKey === 'additional' => 'أذكار إضافية',
            default => 'الأذكار',
        };
    }

    /**
     * @return array<int, string>
     */
    public function buildMessages(AdhkarCollection $collection, bool $includeFooter = true): array
    {
        $items = $collection->items()->where('adhkar_items.active', true)->get()->values();
        $total = $items->count();
        if ($total === 0) {
            return [$collection->title];
        }

        $perMessage = max(1, (int) config('adhkar.items_per_message', 4));
        $limit = (int) config('adhkar.message_limit', 3900);
        $blocks = [];
        foreach ($items as $index => $item) {
            $blocks[] = $this->formatItem($item, $index + 1);
        }

        $groups = [];
        $current = [];
        $placed = 0;
        foreach ($blocks as $block) {
            $candidate = array_merge($current, [$block]);
            $start = $placed + 1;
            $end = $placed + count($candidate);
            $preview = $this->renderChunk($collection, $candidate, $start, $end, $total, $groups === [], false);
            $tooMany = count($candidate) > $perMessage;
            $tooLong = mb_strlen($preview) > $limit;

            if ($current !== [] && ($tooMany || $tooLong)) {
                $groups[] = $current;
                $placed += count($current);
                $current = [$block];
            } else {
                $current = $candidate;
            }
        }

        if ($current !== []) {
            $groups[] = $current;
        }

        $messages = [];
        $cursor = 1;
        $last = array_key_last($groups);
        foreach ($groups as $index => $group) {
            $start = $cursor;
            $end = $cursor + count($group) - 1;
            $cursor = $end + 1;
            $messages[] = $this->renderChunk(
                $collection,
                $group,
                $start,
                $end,
                $total,
                $index === 0,
                $includeFooter && $index === $last,
            );
        }

        return $messages;
    }

    public function nudgeText(string $collectionKey): string
    {
        $evening = str_starts_with($collectionKey, 'evening');

        return __($evening ? 'bot.nudge_evening' : 'bot.nudge_morning', [
            'short' => __('bot.duration_short'),
            'full' => __('bot.duration_full'),
        ]);
    }

    public function modeChoiceText(ReminderType $type): string
    {
        return __('bot.choose_mode', [
            'title' => $type === ReminderType::Evening ? '🌙 أذكار المساء' : '🌅 أذكار الصباح',
            'short' => __('bot.duration_short'),
            'full' => __('bot.duration_full'),
        ]);
    }

    /**
     * Title for a wird file or caption, e.g. "أذكار الصباح — مختصر".
     */
    public function wirdTitle(string $collectionKey, bool $fullOnly = false): string
    {
        $title = $this->collectionHeading($collectionKey);
        if ($this->isSingleVersion($collectionKey)) {
            return $title;
        }
        if ($fullOnly) {
            return $title.' — تتمة الورد الكامل';
        }

        return $title.' — '.(str_contains($collectionKey, '_full') ? __('bot.mode_full') : __('bot.mode_short'));
    }

    public function footerText(string $collectionKey): string
    {
        return $this->footerForKey($collectionKey);
    }

    /**
     * @return Collection<int, AdhkarItem>
     */
    public function itemsForPhase(string $baseKey, string $phase): Collection
    {
        if ($phase === 'f') {
            return $this->fullOnlyItems($baseKey);
        }

        $key = $phase === 'a' ? $this->fullKey($baseKey) : $baseKey;

        return $this->collectionItems($key);
    }

    public function fullKey(string $collectionKey): string
    {
        return str_replace('_short', '_full', $collectionKey);
    }

    /**
     * Items that exist in the full wird and not in the short one, in full-collection order.
     *
     * @return Collection<int, AdhkarItem>
     */
    public function fullOnlyItems(string $shortKey): Collection
    {
        $fullKey = $this->fullKey($shortKey);
        if ($fullKey === $shortKey) {
            return collect();
        }

        $shortKeys = $this->collectionItems($shortKey)->pluck('key')->all();

        return $this->collectionItems($fullKey)
            ->reject(fn (AdhkarItem $item) => in_array($item->key, $shortKeys, true))
            ->values();
    }

    public function renderCard(string $collectionKey, AdhkarItem $item, int $number, int $total, bool $withFooter): string
    {
        $lines = [
            $this->sessionHeader($collectionKey),
            '',
            "الذكر {$number} من {$total}",
            '',
            $this->formatItem($item, $number),
        ];

        if ($withFooter) {
            $lines[] = '';
            $lines[] = $this->footerForKey($collectionKey);
        }

        return trim(implode("\n", $lines));
    }

    /**
     * Whole wird in as few messages as possible, split only between items.
     *
     * @param  Collection<int, AdhkarItem>  $items
     * @return array<int, string>
     */
    public function offlineMessages(string $collectionKey, Collection $items): array
    {
        $limit = 3500;
        $messages = [];
        $current = $this->sessionHeader($collectionKey)."\n\n".__('bot.offline_note');
        $total = $items->count();

        foreach ($items as $index => $item) {
            $block = 'الذكر '.($index + 1)." من {$total}\n\n".$this->formatItem($item, $index + 1);
            $candidate = $current."\n\n".$block;
            if (mb_strlen($candidate) > $limit && trim($current) !== '') {
                $messages[] = trim($current);
                $current = $block;

                continue;
            }
            $current = $candidate;
        }

        if (trim($current) !== '') {
            $messages[] = trim($current);
        }

        return $messages;
    }

    public function formatItem(AdhkarItem $item, int $number): string
    {
        $lines = [];
        if ($item->category !== AdhkarCategory::Waking) {
            $title = $item->title ?: "الذكر {$number}";
            $lines[] = '<b>'.$this->escape($title).'</b>';
        }

        if (filled($item->repetitions_label)) {
            $lines[] = '🔁 <b>التكرار: '.$this->escape($item->repetitions_label).'</b>';
        }

        if ($lines !== []) {
            $lines[] = '';
        }
        $lines[] = $this->escape((string) $item->arabic_text);

        $details = [];
        if (filled($item->virtue)) {
            $details[] = '💡 الفضل: '.$this->escape((string) $item->virtue);
        }

        if (filled($item->source_name)) {
            $source = (string) $item->source_name;
            $number = (string) $item->hadith_number;
            if ($number !== '' && ! str_contains($source, $number)) {
                $source .= ' · '.$number;
            }
            $details[] = '📚 '.$this->escape($source);
        }

        if (filled($item->hadith_grade)) {
            $details[] = '✅ '.$this->escape((string) $item->hadith_grade);
        }

        if ($details !== []) {
            $lines[] = '';
            $lines[] = filled($item->virtue)
                ? $this->detailsBlock($details)
                : implode("\n", $details);
        }

        return implode("\n", $lines);
    }

    /**
     * الفضل والمصدر في اقتباس قابل للطي. المصدر بدون فضل يبقى سطرًا عاديًا.
     *
     * @param  array<int, string>  $escapedLines
     */
    private function detailsBlock(array $escapedLines): string
    {
        return '<blockquote expandable>'.implode("\n", $escapedLines).'</blockquote>';
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param  Collection<int, AdhkarItem>|iterable<AdhkarItem>  $items
     * @return array{content_version_map: array<int, array{key:string,content_version:int}>, content_snapshot_hash: string}
     */
    public function snapshotVersions(iterable $items): array
    {
        $map = [];
        foreach ($items as $item) {
            $map[] = [
                'order' => (int) ($item->pivot?->order ?? $item->order ?? 0),
                'key' => (string) $item->key,
                'content_version' => (int) $item->content_version,
            ];
        }

        usort($map, function (array $left, array $right): int {
            return [$left['order'], $left['key']] <=> [$right['order'], $right['key']];
        });

        $canonical = array_map(fn (array $row) => [
            'order' => $row['order'],
            'key' => $row['key'],
            'content_version' => $row['content_version'],
        ], $map);

        $encoded = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            $encoded = '[]';
        }

        return [
            'content_version_map' => $canonical,
            'content_snapshot_hash' => hash('sha256', $encoded),
        ];
    }

    /**
     * @param  array<int, string>  $blocks
     */
    private function renderChunk(
        AdhkarCollection $collection,
        array $blocks,
        int $start,
        int $end,
        int $total,
        bool $withHeader,
        bool $withFooter,
    ): string {
        $lines = [];
        if ($withHeader) {
            $lines[] = $this->sessionHeader((string) $collection->key);
            $lines[] = '';
        }

        $lines[] = $start === $end
            ? "الذكر {$start} من {$total}"
            : "الذكر {$start}–{$end} من {$total}";
        $lines[] = '';
        $lines[] = implode("\n\n", $blocks);

        if ($withFooter) {
            $lines[] = '';
            $lines[] = $this->footerForKey((string) $collection->key);
        }

        return trim(implode("\n", $lines));
    }

    /**
     * @return Collection<int, AdhkarItem>
     */
    private function collectionItems(string $key): Collection
    {
        $collection = AdhkarCollection::query()->where('key', $key)->where('active', true)->first();
        if ($collection === null) {
            return collect();
        }

        return $collection->items()->where('adhkar_items.active', true)->get()->values();
    }

    private function sessionHeader(string $collectionKey): string
    {
        $icon = match (true) {
            str_starts_with($collectionKey, 'morning') => '🌅',
            str_starts_with($collectionKey, 'evening') => '🌙',
            $collectionKey === 'waking' => '☀️',
            $collectionKey === 'bedtime' => '🛌',
            $collectionKey === 'after_prayer' => '🕌',
            $collectionKey === 'additional' => '🌟',
            default => '📖',
        };
        $title = $icon.' '.$this->collectionHeading($collectionKey);
        if ($this->isSingleVersion($collectionKey) || $icon === '📖') {
            return $title;
        }

        $mode = str_contains($collectionKey, '_full') ? __('bot.mode_full') : __('bot.mode_short');

        return $title."\n🌿 ".$mode;
    }

    private function footerForKey(string $key): string
    {
        return match (true) {
            str_starts_with($key, 'morning') => "🌿 انتهت أذكار الصباح\n\nبارك الله في ذكرك",
            str_starts_with($key, 'evening') => "🌿 انتهت أذكار المساء\n\nبارك الله في ذكرك",
            $key === 'bedtime' => "🌙 انتهت أذكار النوم\n\nنومًا هانئًا، وبارك الله في ذكرك",
            $key === 'waking' => "☀️ انتهت أذكار الاستيقاظ\n\nيومًا مباركًا، وبارك الله في ذكرك",
            $key === 'after_prayer' => "🕌 انتهت أذكار بعد الصلاة\n\nتقبّل الله صلاتك وذكرك",
            $key === 'additional' => "🌟 انتهت الأذكار الإضافية\n\nبارك الله في ذكرك",
            default => 'هذه أذكار اختيارية، خذ منها ما تيسّر.',
        };
    }
}
