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

    public function test_nudge_offers_both_durations_and_the_card_does_not(): void
    {
        $service = app(AdhkarContentService::class);
        $nudge = $service->nudgeText('morning_short');
        $this->assertStringContainsString('حان وقت أذكار الصباح', $nudge);
        $this->assertStringContainsString('مختصر: نحو 5 دقائق', $nudge);
        $this->assertStringContainsString('كامل: نحو 10–15 دقيقة', $nudge);

        $evening = $service->nudgeText('evening_full');
        $this->assertStringContainsString('حان وقت أذكار المساء', $evening);
        $this->assertStringContainsString('مختصر: نحو 5 دقائق', $evening);

        $item = new AdhkarItem([
            'title' => 'آية الكرسي',
            'arabic_text' => 'نص الذكر',
            'repetitions_label' => 'مرة واحدة',
        ]);
        $card = $service->renderCard('morning_short', $item, 3, 9, false);

        $this->assertStringContainsString('الذكر 3 من 9', $card);
        $this->assertStringContainsString('مختصر', $card);
        $this->assertStringNotContainsString('دقائق', $card);
        $this->assertStringNotContainsString('3. آية الكرسي', $card);
        $this->assertStringNotContainsString('انتهت أذكار', $card);

        $last = $service->renderCard('morning_full', $item, 1, 1, true);
        $this->assertStringContainsString('كامل', $last);
        $this->assertStringContainsString('انتهت أذكار الصباح', $last);
    }

    public function test_single_version_collections_have_their_own_labels(): void
    {
        $service = app(AdhkarContentService::class);
        $item = new AdhkarItem(['title' => 'ذكر', 'arabic_text' => 'نص']);

        $bedtime = $service->renderCard('bedtime', $item, 13, 13, true);
        $this->assertStringStartsWith("🛌 أذكار النوم\n\nالذكر 13 من 13", $bedtime);
        $this->assertStringContainsString('انتهت أذكار النوم', $bedtime);
        $this->assertStringNotContainsString('مختصر', $bedtime);

        $waking = $service->renderCard('waking', $item, 1, 2, false);
        $this->assertStringStartsWith("☀️ أذكار الاستيقاظ\n\nالذكر 1 من 2", $waking);

        $prayer = $service->renderCard('after_prayer', $item, 8, 8, true);
        $this->assertStringStartsWith("🕌 أذكار بعد الصلاة\n\nالذكر 8 من 8", $prayer);
        $this->assertStringContainsString('انتهت أذكار بعد الصلاة', $prayer);
        $this->assertStringNotContainsString('مختصر', $prayer);

        $this->assertSame('أذكار النوم', $service->wirdTitle('bedtime'));
        $this->assertSame('أذكار بعد الصلاة', $service->wirdTitle('after_prayer'));
        $this->assertSame('أذكار الاستيقاظ', $service->wirdTitle('waking'));
        $this->assertStringContainsString('انتهت أذكار الاستيقاظ', $service->footerText('waking'));
        $this->assertSame('أذكار الصباح — مختصر', $service->wirdTitle('morning_short'));
        $this->assertSame('أذكار المساء — تتمة الورد الكامل', $service->wirdTitle('evening_short', true));
    }

    public function test_unknown_keys_never_fall_back_to_morning(): void
    {
        $service = app(AdhkarContentService::class);
        $card = $service->renderCard('mystery', new AdhkarItem(['arabic_text' => 'نص']), 1, 1, false);

        $this->assertStringStartsWith('📖 الأذكار', $card);
        $this->assertStringNotContainsString('الصباح', $card);
        $this->assertStringNotContainsString('الصباح', $service->wirdTitle('mystery'));
        $this->assertSame('الأذكار', $service->collectionHeading('mystery'));
    }

    public function test_manual_base_key_resolves_periods_without_reminder_types(): void
    {
        $service = app(AdhkarContentService::class);

        $this->assertSame('morning_short', $service->manualBaseKey('m', 's'));
        $this->assertSame('morning_short', $service->manualBaseKey('m', 'f'));
        $this->assertSame('evening_full', $service->manualBaseKey('e', 'a'));
        $this->assertSame('bedtime', $service->manualBaseKey('b', 'a'));
        $this->assertSame('waking', $service->manualBaseKey('w', 'a'));
        $this->assertSame('after_prayer', $service->manualBaseKey('p', 'a'));
        $this->assertNull($service->manualBaseKey('p', 's'));
        $this->assertNull($service->manualBaseKey('b', 's'));
        $this->assertNull($service->manualBaseKey('w', 'f'));
        $this->assertNull($service->manualBaseKey('x', 'a'));
        $this->assertNull($service->manualBaseKey('m', 'z'));
    }

    public function test_card_keeps_the_text_as_is_and_folds_virtue_and_source_together(): void
    {
        $text = 'سُبْحَانَ اللَّهِ وَبِحَمْدِهِ';
        $item = AdhkarItem::factory()->create([
            'key' => 'card.virtue',
            'title' => 'التسبيح',
            'arabic_text' => $text,
            'repetitions_label' => '100 مرة',
            'virtue' => 'حُطّت خطاياه',
            'source_name' => 'صحيح مسلم',
            'hadith_number' => '2691',
            'hadith_grade' => null,
        ]);

        $card = app(AdhkarContentService::class)->formatItem($item, 1);

        $this->assertStringContainsString("\n\n".$text."\n\n", $card);
        $this->assertStringContainsString('🔁 <b>التكرار: 100 مرة</b>', $card);
        $this->assertMatchesRegularExpression('/<blockquote expandable>💡 الفضل: .*\n📚 صحيح مسلم · 2691<\/blockquote>/u', $card);

        $plain = AdhkarItem::factory()->create([
            'key' => 'card.source',
            'arabic_text' => $text,
            'virtue' => null,
            'source_name' => 'صحيح البخاري',
            'hadith_number' => null,
            'hadith_grade' => null,
        ]);
        $sourceOnly = app(AdhkarContentService::class)->formatItem($plain, 1);
        $this->assertStringContainsString('📚 صحيح البخاري', $sourceOnly);
        $this->assertStringNotContainsString('blockquote', $sourceOnly);
    }

    public function test_card_never_shows_the_narrator_or_guidance_note(): void
    {
        $item = AdhkarItem::factory()->create([
            'key' => 'card.narrator',
            'arabic_text' => 'نص',
            'narrator' => 'أبو هريرة رضي الله عنه',
            'guidance_note' => 'ملاحظة توجيه',
            'source_name' => 'صحيح البخاري',
        ]);

        $card = app(AdhkarContentService::class)->formatItem($item, 1);

        $this->assertStringNotContainsString('أبو هريرة', $card);
        $this->assertStringNotContainsString('الراوي', $card);
        $this->assertStringNotContainsString('ملاحظة توجيه', $card);
    }
}
