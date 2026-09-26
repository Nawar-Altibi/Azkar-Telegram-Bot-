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
                'arabic_text' => str_repeat('نص ', 20).$i,
                'content_version' => $i,
                'order' => $i,
            ]);
            $items[] = $item;
            $collection->items()->attach($item->id, ['order' => $i]);
        }

        config(['adhkar.message_limit' => 120]);
        $service = app(AdhkarContentService::class);
        $messages = $service->buildMessages($collection->fresh());

        $this->assertGreaterThan(1, count($messages));
        foreach ($items as $item) {
            $hits = 0;
            foreach ($messages as $message) {
                if (str_contains($message, $item->arabic_text)) {
                    $hits++;
                }
            }
            $this->assertSame(1, $hits);
        }
        $this->assertStringContainsString('من 3', $messages[0]);
        $this->assertStringNotContainsString('دقائق', $messages[0]);

        $snapshot = $service->snapshotVersions($items);
        $this->assertCount(3, $snapshot['content_version_map']);
        $this->assertSame(64, strlen($snapshot['content_snapshot_hash']));
    }

    public function test_snapshot_hash_is_stable_for_the_same_logical_order(): void
    {
        $first = new AdhkarItem(['key' => 'b.item', 'content_version' => 2, 'order' => 2]);
        $second = new AdhkarItem(['key' => 'a.item', 'content_version' => 4, 'order' => 1]);
        $service = app(AdhkarContentService::class);

        $forward = $service->snapshotVersions([$second, $first]);
        $reversed = $service->snapshotVersions([$first, $second]);

        $this->assertSame($forward['content_snapshot_hash'], $reversed['content_snapshot_hash']);
        $this->assertSame(
            ['a.item', 'b.item'],
            array_column($forward['content_version_map'], 'key')
        );

        $reordered = new AdhkarItem(['key' => 'a.item', 'content_version' => 4, 'order' => 9]);
        $different = $service->snapshotVersions([$reordered, $first]);
        $this->assertNotSame($forward['content_snapshot_hash'], $different['content_snapshot_hash']);
    }

    public function test_nudge_includes_an_approximate_duration_and_the_card_does_not(): void
    {
        $service = app(AdhkarContentService::class);
        $nudge = $service->nudgeText('morning_short');
        $this->assertStringContainsString('وردك المختصر يستغرق تقريبًا 5 دقائق.', $nudge);
        $this->assertStringNotContainsString('10–15', $nudge);

        $full = $service->nudgeText('evening_full');
        $this->assertStringContainsString('حان وقت أذكار المساء', $full);
        $this->assertStringContainsString('الورد الكامل يستغرق تقريبًا 10–15 دقيقة.', $full);

        $item = new AdhkarItem([
            'title' => 'آية الكرسي',
            'arabic_text' => 'نص الذكر',
            'repetitions_label' => 'مرة واحدة',
        ]);
        $card = $service->renderCard('morning_short', $item, 3, 9, false);

        $this->assertStringContainsString("الذكر 3 من 9", $card);
        $this->assertStringContainsString('مختصر', $card);
        $this->assertStringNotContainsString('دقائق', $card);
        $this->assertStringNotContainsString('3. آية الكرسي', $card);
        $this->assertStringNotContainsString('انتهت أذكار', $card);

        $last = $service->renderCard('morning_full', $item, 1, 1, true);
        $this->assertStringContainsString('كامل', $last);
        $this->assertStringContainsString('انتهت أذكار الصباح', $last);
    }
}
