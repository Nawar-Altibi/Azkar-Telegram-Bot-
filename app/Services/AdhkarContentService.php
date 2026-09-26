<?php

namespace App\Services;

use App\Enums\AdhkarMode;
use App\Enums\ReminderType;
use App\Models\AdhkarCollection;
use App\Models\AdhkarItem;
use App\Models\User;
use Illuminate\Support\Collection;

class AdhkarContentService
{
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
        $full = str_contains($collectionKey, '_full');

        return __($evening ? 'bot.nudge_evening' : 'bot.nudge_morning', [
            'duration' => __($full ? 'bot.nudge_duration_full' : 'bot.nudge_duration_short'),
        ]);
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
            $block = "الذكر ".($index + 1)." من {$total}\n\n".$this->formatItem($item, $index + 1);
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

    /**
     * @param  bool  $detailed  يُظهر الراوي وملاحظة التوجيه، ويُستخدم للذكر الإضافي المعروض منفردًا.
     */
    public function formatItem(AdhkarItem $item, int $number, bool $detailed = false): string
    {
        $lines = [];
        $title = $item->title ?: "الذكر {$number}";
        $lines[] = '<b>'.$this->escape($title).'</b>';

        if (filled($item->repetitions_label)) {
            $lines[] = '🔁 التكرار: '.$this->escape($item->repetitions_label);
        }

        $lines[] = '';
        $lines[] = $this->escape((string) $item->arabic_text);

        if (filled($item->virtue)) {
            $lines[] = '';
            $lines[] = $this->blockquote('💡 الفضل: '.$item->virtue);
        }

        if (filled($item->source_name)) {
            $source = (string) $item->source_name;
            $number = (string) $item->hadith_number;
            if ($number !== '' && ! str_contains($source, $number)) {
                $source .= ' · '.$number;
            }
            $lines[] = '<i>📚 '.$this->escape($source).'</i>';
        }

        if (filled($item->hadith_grade)) {
            $lines[] = '✅ '.$this->escape((string) $item->hadith_grade);
        }

        if ($detailed && filled($item->narrator)) {
            $lines[] = '🗣️ الراوي: '.$this->escape($item->narrator);
        }

        if ($detailed && filled($item->guidance_note)) {
            $lines[] = '📌 '.$this->escape($item->guidance_note);
        }

        return implode("\n", $lines);
    }

    /**
     * الفضل الطويل يُطوى في اقتباس قابل للتوسعة حتى لا تطول الرسالة على القارئ.
     */
    private function blockquote(string $text): string
    {
        $expandable = mb_strlen($text) > 200 ? ' expandable' : '';

        return '<blockquote'.$expandable.'>'.$this->escape($text).'</blockquote>';
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
        $evening = str_starts_with($collectionKey, 'evening');
        $full = str_contains($collectionKey, '_full');
        $title = $evening ? '🌙 أذكار المساء' : '🌅 أذكار الصباح';
        $mode = $full ? __('bot.mode_full') : __('bot.mode_short');

        return $title."\n🌿 ".$mode;
    }

    private function footerForKey(string $key): string
    {
        return match (true) {
            str_starts_with($key, 'morning') => "🌿 انتهت أذكار الصباح\n\nبارك الله في ذكرك",
            str_starts_with($key, 'evening') => "🌿 انتهت أذكار المساء\n\nبارك الله في ذكرك",
            default => 'هذه أذكار اختيارية، خذ منها ما تيسّر.',
        };
    }
}
