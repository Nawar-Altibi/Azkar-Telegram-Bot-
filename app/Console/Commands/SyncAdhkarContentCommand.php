<?php

namespace App\Console\Commands;

use App\Services\ContentSyncService;
use Illuminate\Console\Command;

class SyncAdhkarContentCommand extends Command
{
    protected $signature = 'adhkar:sync
                            {--if-changed : Skip when the content source is unchanged and the database already holds it}';

    protected $description = 'Upsert adhkar content from structured seed files without deleting existing rows.';

    public function handle(ContentSyncService $sync): int
    {
        $stats = $this->option('if-changed') ? $sync->syncIfChanged() : $sync->syncLocked();

        if ($stats === null) {
            $this->info('Adhkar content unchanged; sync skipped.');

            return self::SUCCESS;
        }

        $this->info('Adhkar content synced.');
        $this->table(array_keys($stats), [array_values($stats)]);

        return self::SUCCESS;
    }
}
