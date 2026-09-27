<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseStatus
{
    private const COUNTED_TABLES = [
        'users',
        'reminder_logs',
        'telegram_updates',
        'adhkar_collections',
        'adhkar_items',
        'adhkar_collection_items',
        'cache',
        'cache_locks',
        'sessions',
        'jobs',
        'failed_jobs',
    ];

    /**
     * Connection identity without credentials: driver, database name and the host's domain only.
     *
     * @return array{driver: string, database: string, host: string}
     */
    public function identity(): array
    {
        $connection = DB::connection();
        $connection->getPdo();

        return [
            'driver' => $connection->getDriverName(),
            'database' => (string) $connection->getDatabaseName(),
            'host' => $this->maskHost((string) $connection->getConfig('host')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $counts = [];
        foreach (self::COUNTED_TABLES as $table) {
            $counts[$table] = Schema::hasTable($table) ? DB::table($table)->count() : null;
        }

        return $this->identity() + [
            'migrations_applied' => Schema::hasTable('migrations') ? DB::table('migrations')->count() : 0,
            'counts' => $counts,
        ];
    }

    private function maskHost(string $host): string
    {
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) || ! str_contains($host, '.')) {
            return $host === '' ? '' : 'local';
        }

        $labels = explode('.', $host);

        return '*.'.implode('.', array_slice($labels, -2));
    }
}
