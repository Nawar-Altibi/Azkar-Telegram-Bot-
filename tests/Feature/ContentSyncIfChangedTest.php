<?php

namespace Tests\Feature;

use App\Models\AdhkarCollection;
use App\Models\AdhkarItem;
use App\Services\ContentSyncService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ContentSyncIfChangedTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        AdhkarItem::flushEventListeners();

        parent::tearDown();
    }

    private function tempCopy(string $source): string
    {
        $path = tempnam(sys_get_temp_dir(), 'adhkar').'.php';
        copy($source, $path);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * Service whose sources can be edited without touching the real content.
     *
     * @param  list<string>|null  $dataFiles
     * @param  list<string>|null  $sourceFiles
     */
    private function service(?array $dataFiles = null, ?array $sourceFiles = null, int $lockWait = 120): ContentSyncService
    {
        return new class($dataFiles, $sourceFiles, $lockWait) extends ContentSyncService
        {
            public function __construct(private ?array $data, private ?array $sources, int $lockWait)
            {
                $this->lockWaitSeconds = $lockWait;
            }

            protected function dataFiles(): array
            {
                return $this->data ?? parent::dataFiles();
            }

            protected function sourceFiles(): array
            {
                return $this->sources ?? parent::sourceFiles();
            }
        };
    }

    public function test_first_run_populates_and_second_run_skips(): void
    {
        $sync = app(ContentSyncService::class);

        $first = $sync->syncIfChanged();
        $this->assertNotNull($first);
        $this->assertGreaterThan(0, $first['created']);
        $this->assertTrue($sync->isComplete());
        $this->assertSame($sync->sourceHash(), Cache::get(ContentSyncService::MARKER_KEY));

        $writes = 0;
        DB::listen(function ($query) use (&$writes) {
            if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                $writes++;
            }
        });
        $this->assertNull($sync->syncIfChanged());
        $this->assertSame(0, $writes);
    }

    public function test_modified_content_file_triggers_sync(): void
    {
        $morning = $this->tempCopy(database_path('data/adhkar/morning.php'));
        $files = [$morning, database_path('data/adhkar/evening.php'), database_path('data/adhkar/additional.php')];
        $sync = $this->service($files);
        $sync->syncIfChanged();
        $this->assertNull($sync->syncIfChanged());

        file_put_contents($morning, "\n// edited\n", FILE_APPEND);

        $this->assertNotNull($sync->syncIfChanged());
        $this->assertSame($sync->sourceHash(), Cache::get(ContentSyncService::MARKER_KEY));
    }

    public function test_changed_sync_logic_triggers_sync(): void
    {
        $logic = $this->tempCopy(app_path('Services/ContentSyncService.php'));
        $sources = [
            database_path('data/adhkar/morning.php'),
            database_path('data/adhkar/evening.php'),
            database_path('data/adhkar/additional.php'),
            $logic,
        ];
        $sync = $this->service(null, $sources);
        $sync->syncIfChanged();
        $this->assertNull($sync->syncIfChanged());

        file_put_contents($logic, "\n// new mapping rule\n", FILE_APPEND);

        $this->assertNotNull($sync->syncIfChanged());
    }

    public function test_real_source_hash_covers_the_sync_service_file(): void
    {
        $base = app(ContentSyncService::class)->sourceHash();
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'adhkar-'.uniqid();
        mkdir($dir);
        $logic = $dir.DIRECTORY_SEPARATOR.'ContentSyncService.php';
        copy(app_path('Services/ContentSyncService.php'), $logic);
        $this->tempFiles[] = $logic;
        $sources = [
            database_path('data/adhkar/morning.php'),
            database_path('data/adhkar/evening.php'),
            database_path('data/adhkar/additional.php'),
            $logic,
        ];

        $this->assertSame($base, $this->service(null, $sources)->sourceHash());
        file_put_contents($logic, "\n// changed\n", FILE_APPEND);
        $this->assertNotSame($base, $this->service(null, $sources)->sourceHash());
        unlink($logic);
        rmdir($dir);
        $this->tempFiles = [];
    }

    public function test_empty_database_with_a_matching_marker_still_syncs(): void
    {
        $sync = app(ContentSyncService::class);
        $sync->syncIfChanged();

        DB::table('adhkar_collection_items')->delete();
        AdhkarItem::query()->delete();
        AdhkarCollection::query()->delete();
        $this->assertSame($sync->sourceHash(), Cache::get(ContentSyncService::MARKER_KEY));

        $this->assertNotNull($sync->syncIfChanged());
        $this->assertTrue($sync->isComplete());
    }

    public function test_missing_item_triggers_sync(): void
    {
        $sync = app(ContentSyncService::class);
        $sync->syncIfChanged();
        AdhkarItem::query()->where('key', 'evening.02')->delete();

        $this->assertFalse($sync->isComplete());
        $this->assertNotNull($sync->syncIfChanged());
        $this->assertTrue(AdhkarItem::query()->where('key', 'evening.02')->exists());
    }

    public function test_missing_collection_links_trigger_sync(): void
    {
        $sync = app(ContentSyncService::class);
        $sync->syncIfChanged();
        $collection = AdhkarCollection::query()->where('key', 'morning_full')->sole();
        $firstLink = DB::table('adhkar_collection_items')->where('collection_id', $collection->id)->min('id');
        DB::table('adhkar_collection_items')->where('id', $firstLink)->delete();

        $this->assertFalse($sync->isComplete());
        $this->assertNotNull($sync->syncIfChanged());
        $this->assertTrue($sync->isComplete());
    }

    public function test_inactive_collection_or_item_triggers_sync(): void
    {
        $sync = app(ContentSyncService::class);
        $sync->syncIfChanged();

        AdhkarCollection::query()->where('key', 'evening_short')->update(['active' => false]);
        $this->assertNotNull($sync->syncIfChanged());
        $this->assertTrue(AdhkarCollection::query()->where('key', 'evening_short')->value('active'));

        AdhkarItem::query()->where('key', 'morning.01')->update(['active' => false]);
        $this->assertNotNull($sync->syncIfChanged());
        $this->assertTrue((bool) AdhkarItem::query()->where('key', 'morning.01')->value('active'));
    }

    public function test_interrupted_sync_leaves_no_marker_and_next_run_recovers(): void
    {
        $sync = app(ContentSyncService::class);
        $created = 0;
        AdhkarItem::creating(function () use (&$created) {
            if (++$created === 10) {
                throw new RuntimeException('container killed mid-sync');
            }
        });

        try {
            $sync->syncIfChanged();
            $this->fail('Expected the interrupted sync to throw.');
        } catch (RuntimeException) {
        }

        $this->assertNull(Cache::get(ContentSyncService::MARKER_KEY));
        $this->assertSame(0, AdhkarItem::query()->count());
        $this->assertTrue(Cache::lock(ContentSyncService::LOCK_KEY, 1)->get(), 'lock must be released');
        Cache::lock(ContentSyncService::LOCK_KEY)->forceRelease();

        AdhkarItem::flushEventListeners();
        $this->assertNotNull($sync->syncIfChanged());
        $this->assertTrue($sync->isComplete());
    }

    public function test_concurrent_startup_is_serialized_by_the_shared_lock(): void
    {
        $other = Cache::lock(ContentSyncService::LOCK_KEY, 300);
        $this->assertTrue($other->get());

        try {
            $this->service(lockWait: 1)->syncIfChanged();
            $this->fail('A second instance must wait for the running sync.');
        } catch (LockTimeoutException) {
        }
        $this->assertSame(0, AdhkarItem::query()->count());

        // The first instance finishes; the next instance sees the marker and complete data, and skips.
        $other->release();
        $this->assertNotNull(app(ContentSyncService::class)->syncIfChanged());
        $this->assertNull($this->service(lockWait: 1)->syncIfChanged());
    }

    public function test_commands_forced_and_if_changed(): void
    {
        $this->artisan('adhkar:sync --if-changed')->expectsOutput('Adhkar content synced.')->assertSuccessful();
        $this->artisan('adhkar:sync --if-changed')->expectsOutput('Adhkar content unchanged; sync skipped.')->assertSuccessful();
        $this->artisan('adhkar:sync')->expectsOutput('Adhkar content synced.')->assertSuccessful();
    }
}
