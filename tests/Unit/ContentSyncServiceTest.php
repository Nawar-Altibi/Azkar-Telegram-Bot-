<?php

namespace Tests\Unit;

use App\Models\AdhkarCollection;
use App\Models\AdhkarItem;
use App\Services\ContentSyncService;
use Tests\TestCase;

class ContentSyncServiceTest extends TestCase
{
    public function test_sync_is_idempotent_and_excludes_warning_section(): void
    {
        $sync = app(ContentSyncService::class);
        $first = $sync->sync();
        $second = $sync->sync();

        $this->assertGreaterThan(0, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(0, $second['updated']);
        $this->assertGreaterThan(0, $second['unchanged']);

        $this->assertFalse(AdhkarItem::query()->where('arabic_text', 'like', '%مخّ العبادة%')->exists());
        $this->assertFalse(AdhkarItem::query()->where('title', 'like', '%تحتاج تنبيه%')->exists());

        $this->assertTrue(AdhkarCollection::query()->where('key', 'morning_short')->exists());
        $this->assertTrue(AdhkarItem::query()->where('key', 'morning.01')->exists());
        $this->assertTrue(AdhkarItem::query()->where('key', 'additional.01')->exists());
        $this->assertSame(13, AdhkarCollection::query()->where('key', 'bedtime')->sole()->items()->count());
        $this->assertSame(3, AdhkarCollection::query()->where('key', 'waking')->sole()->items()->count());
        $this->assertSame(8, AdhkarCollection::query()->where('key', 'after_prayer')->sole()->items()->count());

        $short = AdhkarCollection::query()->where('key', 'morning_short')->first();
        $full = AdhkarCollection::query()->where('key', 'morning_full')->first();
        $this->assertGreaterThan($short->items()->count(), $full->items()->count());

        $morningKeys = $short->items()->pluck('adhkar_items.key')->all();
        $this->assertSame(['morning.01', 'morning.02', 'morning.salat_nabi'], array_slice($morningKeys, 0, 3));
        $eveningKeys = AdhkarCollection::query()->where('key', 'evening_short')->first()->items()->pluck('adhkar_items.key')->all();
        $this->assertSame(['evening.01', 'evening.02', 'evening.salat_nabi'], array_slice($eveningKeys, 0, 3));
    }

    public function test_hash_change_bumps_content_version(): void
    {
        $sync = app(ContentSyncService::class);
        $sync->sync();

        $item = AdhkarItem::query()->where('key', 'morning.01')->first();
        $originalVersion = $item->content_version;
        $item->update(['arabic_text' => 'نص مختلف مؤقت', 'source_hash' => 'old']);

        $sync->sync();
        $item->refresh();

        $this->assertSame($originalVersion + 1, $item->content_version);
        $this->assertStringContainsString('اللَّهُ لَا إِلَهَ إِلَّا هُوَ', $item->arabic_text);
    }
}
