<?php

namespace App\Console\Commands;

use App\Services\StaleRecoveryService;
use Illuminate\Console\Command;

class RecoverStaleWorkCommand extends Command
{
    protected $signature = 'adhkar:recover-stale';

    protected $description = 'Re-dispatch stale telegram updates and pending reminder logs.';

    public function handle(StaleRecoveryService $recovery): int
    {
        $result = $recovery->recover();

        $this->info("Recovered {$result['updates']} telegram update(s) and {$result['reminders']} reminder log(s).");

        return self::SUCCESS;
    }
}
