<?php

namespace App\Console\Commands;

use App\Services\ContentSyncService;
use Illuminate\Console\Command;

class SyncAdhkarContentCommand extends Command
{
    protected $signature = 'adhkar:sync';

    protected $description = 'Upsert adhkar content from structured seed files without deleting existing rows.';

    public function handle(ContentSyncService $sync): int
    {
        $stats = $sync->sync();
        $this->info('Adhkar content synced.');
        $this->table(array_keys($stats), [array_values($stats)]);

        return self::SUCCESS;
    }
}
