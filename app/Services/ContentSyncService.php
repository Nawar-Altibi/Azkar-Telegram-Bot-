<?php

namespace App\Services;

use App\Models\AdhkarCollection;
use App\Models\AdhkarItem;
use Illuminate\Support\Facades\DB;

class ContentSyncService
{
    /**
     * @return array{created:int,updated:int,unchanged:int,deactivated:int}
     */
    public function sync(): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'deactivated' => 0];

        DB::transaction(function () use (&$stats) {
            $this->syncCollections();

            foreach (['morning', 'evening', 'additional'] as $file) {
                $path = database_path("data/adhkar/{$file}.php");
                $items = require $path;

                foreach ($items as $data) {
                    $this->syncItem($data, $stats);
                }
            }
        });

        return $stats;
    }

    private function syncCollections(): void
    {
        $collections = [
            ['key' => 'morning_short', 'category' => 'morning', 'variant' => 'short', 'title' => 'أذكار الصباح — المختصر', 'order' => 1],
            ['key' => 'morning_full', 'category' => 'morning', 'variant' => 'full', 'title' => 'أذكار الصباح — الكامل', 'order' => 2],
            ['key' => 'evening_short', 'category' => 'evening', 'variant' => 'short', 'title' => 'أذكار المساء — المختصر', 'order' => 3],
            ['key' => 'evening_full', 'category' => 'evening', 'variant' => 'full', 'title' => 'أذكار المساء — الكامل', 'order' => 4],
            ['key' => 'additional', 'category' => 'additional', 'variant' => null, 'title' => 'أذكار وفضائل إضافية', 'order' => 5],
        ];

        foreach ($collections as $collection) {
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
