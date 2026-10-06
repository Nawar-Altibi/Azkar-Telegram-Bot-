<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Every container start step in one PHP process: a cold start pays for one framework boot and one
 * database connection instead of one per step.
 */
class PrepareContainerCommand extends Command
{
    protected $signature = 'adhkar:prepare
                            {--wait=60 : Seconds to keep retrying the database connection}
                            {--cache-config : Cache config afterwards; routes, events and views are cached in the image}';

    protected $description = 'Wait for the database, migrate, sync content if changed and optionally cache config.';

    public function handle(): int
    {
        $steps = [];
        // migrate creates a missing sqlite file, so only server databases are waited for.
        if (config('database.default') !== 'sqlite') {
            $steps['database'] = fn () => $this->call('adhkar:db-status', ['--wait' => $this->option('wait')]);
        }
        $steps['migrate'] = fn () => $this->call('migrate', ['--force' => true]);
        $steps['sync'] = fn () => $this->call('adhkar:sync', ['--if-changed' => true]);
        if ($this->option('cache-config')) {
            $steps['config'] = fn () => $this->call('config:cache');
        }

        $timings = [];
        foreach ($steps as $name => $step) {
            $started = hrtime(true);
            if ($step() !== self::SUCCESS) {
                $this->error("Container prepare failed at {$name}.");

                return self::FAILURE;
            }
            $timings[] = sprintf('%s %dms', $name, (hrtime(true) - $started) / 1_000_000);
        }

        $this->line('Container prepared: '.implode(', ', $timings));

        return self::SUCCESS;
    }
}
