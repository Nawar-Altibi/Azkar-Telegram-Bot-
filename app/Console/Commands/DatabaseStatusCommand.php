<?php

namespace App\Console\Commands;

use App\Support\DatabaseStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DatabaseStatusCommand extends Command
{
    protected $signature = 'adhkar:db-status
                            {--wait=0 : Seconds to keep retrying the connection before failing}';

    protected $description = 'Print the active database driver, name and host domain without credentials.';

    public function handle(DatabaseStatus $status): int
    {
        $deadline = time() + max(0, (int) $this->option('wait'));

        while (true) {
            try {
                $identity = $status->identity();
                $this->line(sprintf(
                    'Database driver: %s, database: %s, host: %s',
                    $identity['driver'],
                    $identity['database'],
                    $identity['host'] !== '' ? $identity['host'] : 'n/a',
                ));

                return self::SUCCESS;
            } catch (\Throwable $e) {
                DB::purge();

                if (time() >= $deadline) {
                    // Exception messages can echo the DSN, so only the class and SQLSTATE are reported.
                    $this->error(sprintf('Database connection failed: %s (code %s)', $e::class, (string) $e->getCode()));

                    return self::FAILURE;
                }

                sleep(2);
            }
        }
    }
}
