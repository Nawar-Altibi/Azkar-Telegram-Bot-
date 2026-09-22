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
        $limit = (int) config('adhkar.message_limit', 3900);
        $chunks = [];
        $current = $collection->title."\n";

        foreach ($collection->items()->where('adhkar_items.active', true)->get() as $index => $item) {
            $block = $this->formatItem($item, $index + 1);

            if (mb_strlen($current."\n".$block) > $limit) {
                $chunks[] = trim($current);
                $current = $block;
            } else {
                $current .= "\n\n".$block;
            }
        }

        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }

        if ($includeFooter && $chunks !== []) {
            $last = array_key_last($chunks);
            $chunks[$last] .= "\n\n".$this->footerFor($collection);
        }

        return $chunks === [] ? [$collection->title] : $chunks;
    }

    public function formatItem(AdhkarItem $item, int $number): string
    {
        $lines = [];
        $title = $item->title ?: "الذكر {$number}";
        $lines[] = "{$number}. {$title}";

        if (filled($item->repetitions_label)) {
            $lines[] = 'التكرار: '.$item->repetitions_label;
        }

        $lines[] = '';
        $lines[] = $item->arabic_text;

        if (filled($item->virtue)) {
            $lines[] = '';
            $lines[] = 'الفضل: '.$item->virtue;
        }

        if (filled($item->source_name)) {
            $source = $item->source_name;
            if (filled($item->hadith_number)) {
                $source .= ' — '.$item->hadith_number;
            }
            $lines[] = 'المصدر: '.$source;
        }

        return implode("\n", $lines);
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
                'key' => $item->key,
                'content_version' => (int) $item->content_version,
            ];
        }

        $encoded = json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'content_version_map' => $map,
            'content_snapshot_hash' => hash('sha256', $encoded ?: ''),
        ];
    }

    private function footerFor(AdhkarCollection $collection): string
    {
        return match ($collection->key) {
            'morning_short', 'morning_full' => 'تم إرسال أذكار الصباح 🌅',
            'evening_short', 'evening_full' => 'تم إرسال أذكار المساء 🌙',
            default => 'هذه أذكار اختيارية، خذ منها ما تيسّر.',
        };
    }
}
