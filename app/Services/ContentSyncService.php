<?php

namespace App\Services;

use App\Models\AdhkarCollection;
use App\Models\AdhkarItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ContentSyncService
{
    public const MARKER_KEY = 'adhkar:content_sync_hash';

    public const LOCK_KEY = 'adhkar:content-sync';

    private const LOCK_SECONDS = 300;

    protected int $lockWaitSeconds = 120;

    /**
     * Startup path: skips only when the source hash matches the last successful sync
     * AND the database actually holds the expected content.
     *
     * @return array{created:int,updated:int,unchanged:int,deactivated:int}|null null when skipped
     */
    public function syncIfChanged(): ?array
    {
        $hash = $this->sourceHash();
        if ($this->isCurrent($hash)) {
            return null;
        }

        return Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS)->block($this->lockWaitSeconds, function () use ($hash) {
            // Another instance may have finished the sync while this one waited.
            if ($this->isCurrent($hash)) {
                return null;
            }

            return $this->syncAndMark($hash);
        });
    }

    /**
     * Forced sync, serialized with concurrent startups.
     *
     * @return array{created:int,updated:int,unchanged:int,deactivated:int}
     */
    public function syncLocked(): array
    {
        return Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS)->block(
            $this->lockWaitSeconds,
            fn () => $this->syncAndMark($this->sourceHash()),
        );
    }

    /**
     * @return array{created:int,updated:int,unchanged:int,deactivated:int}
     */
    public function sync(): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'deactivated' => 0];

        DB::transaction(function () use (&$stats) {
            $this->syncCollections();

            foreach ($this->dataFiles() as $path) {
                $items = require $path;

                foreach ($items as $data) {
                    $this->syncItem($data, $stats);
                }
            }
        });

        return $stats;
    }

    public function sourceHash(): string
    {
        $context = hash_init('sha256');
        foreach ($this->sourceFiles() as $path) {
            hash_update($context, basename($path)."\0");
            hash_update_file($context, $path);
        }

        return hash_final($context);
    }

    /**
     * The database is the source of truth: an empty, partial, or hand-edited content set is never "complete".
     */
    public function isComplete(): bool
    {
        $collectionKeys = array_column($this->collectionDefinitions(), 'key');
        if (AdhkarCollection::query()->whereIn('key', $collectionKeys)->where('active', true)->count() !== count($collectionKeys)) {
            return false;
        }

        $itemKeys = [];
        $activeKeys = [];
        $links = 0;
        foreach ($this->dataFiles() as $path) {
            foreach (require $path as $data) {
                $itemKeys[] = $data['key'];
                if ($data['active'] ?? true) {
                    $activeKeys[] = $data['key'];
                }
                $links += count($data['collections'] ?? []);
            }
        }

        if (AdhkarItem::query()->whereIn('key', $itemKeys)->count() !== count($itemKeys)) {
            return false;
        }

        if (AdhkarItem::query()->whereIn('key', $activeKeys)->where('active', true)->count() !== count($activeKeys)) {
            return false;
        }

        $storedLinks = DB::table('adhkar_collection_items')
            ->join('adhkar_items', 'adhkar_items.id', '=', 'adhkar_collection_items.item_id')
            ->whereIn('adhkar_items.key', $itemKeys)
            ->count();

        return $storedLinks === $links;
    }

    /**
     * @return list<string>
     */
    protected function dataFiles(): array
    {
        return array_map(
            fn (string $file) => database_path("data/adhkar/{$file}.php"),
            ['morning', 'evening', 'additional', 'bedtime', 'waking'],
        );
    }

    /**
     * Everything that determines the synced result: the content files and this sync logic.
     *
     * @return list<string>
     */
    protected function sourceFiles(): array
    {
        return [...$this->dataFiles(), __FILE__];
    }

    private function isCurrent(string $hash): bool
    {
        return Cache::get(self::MARKER_KEY) === $hash && $this->isComplete();
    }

    /**
     * @return array{created:int,updated:int,unchanged:int,deactivated:int}
     */
    private function syncAndMark(string $hash): array
    {
        $stats = $this->sync();
        // Written only after the sync transaction committed; an interrupted sync leaves no marker.
        Cache::forever(self::MARKER_KEY, $hash);

        return $stats;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectionDefinitions(): array
    {
        return [
            ['key' => 'morning_short', 'category' => 'morning', 'variant' => 'short', 'title' => 'أذكار الصباح — المختصر', 'order' => 1],
            ['key' => 'morning_full', 'category' => 'morning', 'variant' => 'full', 'title' => 'أذكار الصباح — الكامل', 'order' => 2],
            ['key' => 'evening_short', 'category' => 'evening', 'variant' => 'short', 'title' => 'أذكار المساء — المختصر', 'order' => 3],
            ['key' => 'evening_full', 'category' => 'evening', 'variant' => 'full', 'title' => 'أذكار المساء — الكامل', 'order' => 4],
            ['key' => 'additional', 'category' => 'additional', 'variant' => null, 'title' => 'أذكار وفضائل إضافية', 'order' => 5],
            ['key' => 'bedtime', 'category' => 'bedtime', 'variant' => null, 'title' => 'أذكار النوم', 'order' => 6],
            ['key' => 'waking', 'category' => 'waking', 'variant' => null, 'title' => 'أذكار الاستيقاظ', 'order' => 7],
        ];
    }

    private function syncCollections(): void
    {
        foreach ($this->collectionDefinitions() as $collection) {
            AdhkarCollection::query()->updateOrCreate(
                ['key' => $collection['key']],
                $collection + ['active' => true],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{created:int,updated:int,unchanged:int,deactivated:int}  $stats
     */
    private function syncItem(array $data, array &$stats): void
    {
        $collections = $data['collections'] ?? [];
        unset($data['collections']);

        $data['active'] = $data['active'] ?? true;
        $hash = $this->hash($data);

        $item = AdhkarItem::query()->where('key', $data['key'])->first();

        if ($item === null) {
            $item = AdhkarItem::query()->create($data + [
                'source_hash' => $hash,
                'content_version' => 1,
            ]);
            $stats['created']++;
        } elseif ($item->source_hash !== $hash) {
            $item->fill($data);
            $item->source_hash = $hash;
            $item->content_version = $item->content_version + 1;
            $item->save();
            $stats['updated']++;
        } else {
            if (array_key_exists('active', $data) && (bool) $data['active'] !== $item->active) {
                $item->active = (bool) $data['active'];
                $item->save();
                if (! $item->active) {
                    $stats['deactivated']++;
                }
            } else {
                $stats['unchanged']++;
            }
        }

        $sync = [];
        foreach ($collections as $index => $collectionKey) {
            $collection = AdhkarCollection::query()->where('key', $collectionKey)->first();
            if ($collection) {
                $sync[$collection->id] = ['order' => $data['order'] ?? $index];
            }
        }
        $item->collections()->sync($sync);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function hash(array $data): string
    {
        $canonical = [
            $data['arabic_text'] ?? '',
            $data['repetitions_count'] ?? null,
            $data['repetitions_label'] ?? null,
            $data['timing'] ?? null,
            $data['virtue'] ?? null,
            $data['source_text'] ?? null,
            $data['source_name'] ?? null,
            $data['hadith_number'] ?? null,
            $data['hadith_grade'] ?? null,
            $data['narrator'] ?? null,
            $data['guidance_note'] ?? null,
            $data['notes'] ?? null,
            $data['title'] ?? null,
        ];

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
