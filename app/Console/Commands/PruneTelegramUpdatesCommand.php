<?php

namespace App\Console\Commands;

use App\Enums\TelegramUpdateStatus;
use App\Models\TelegramUpdate;
use Illuminate\Console\Command;

class PruneTelegramUpdatesCommand extends Command
{
    protected $signature = 'bot:prune-updates {--days=30}';

    protected $description = 'Delete finished Telegram updates older than the retention window.';

    private const BATCH_SIZE = 5000;

    public function handle(): int
    {
        $cutoff = now()->subDays(max(1, (int) $this->option('days')));
        $deleted = 0;

        // Batches keep each DELETE short so webhook inserts are never blocked for long.
        do {
            $ids = TelegramUpdate::query()
                ->whereIn('status', [TelegramUpdateStatus::Processed, TelegramUpdateStatus::Failed])
                ->where('received_at', '<', $cutoff)
                ->limit(self::BATCH_SIZE)
                ->pluck('id');

            $deleted += $ids->isEmpty() ? 0 : TelegramUpdate::query()->whereKey($ids)->delete();
        } while ($ids->count() === self::BATCH_SIZE);

        $this->info("Deleted {$deleted} old Telegram updates.");

        return self::SUCCESS;
    }
}
