<?php

namespace Tests\Unit;

use App\Enums\AdhkarCategory;
use App\Models\AdhkarCollection;
use App\Models\AdhkarItem;
use App\Services\AdhkarContentService;
use Tests\TestCase;

class AdhkarContentServiceTest extends TestCase
{
    public function test_it_chunks_messages_under_the_limit_and_snapshots_versions(): void
    {
        $collection = AdhkarCollection::query()->create([
            'key' => 'morning_short',
            'category' => AdhkarCategory::Morning,
            'variant' => 'short',
            'title' => 'أذكار الصباح',
            'active' => true,
            'order' => 1,
        ]);

        $items = [];
        for ($i = 1; $i <= 3; $i++) {
            $item = AdhkarItem::factory()->create([
                'key' => "morning.chunk.{$i}",
                'title' => "ذكر {$i}",
                'arabic_text' => str_repeat('نص ', 20),
                'content_version' => $i,
                'order' => $i,
            ]);
            $items[] = $item;
            $collection->items()->attach($item->id, ['order' => $i]);
        }

        config(['adhkar.message_limit' => 120]);
        $service = app(AdhkarContentService::class);
        $messages = $service->buildMessages($collection->fresh());

        $this->assertNotEmpty($messages);
        foreach ($messages as $message) {
            $this->assertLessThanOrEqual(160, mb_strlen($message));
        }

        $snapshot = $service->snapshotVersions($items);
        $this->assertCount(3, $snapshot['content_version_map']);
        $this->assertSame(64, strlen($snapshot['content_snapshot_hash']));
    }
}
