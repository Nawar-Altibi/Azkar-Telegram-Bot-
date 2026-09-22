<?php

namespace App\Console\Commands;

use App\Enums\TelegramUpdateStatus;
use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\TelegramUpdate;
use App\Services\ReminderDispatchService;
use Illuminate\Console\Command;

class RecoverStaleWorkCommand extends Command
{
    protected $signature = 'adhkar:recover-stale';

    protected $description = 'Re-dispatch stale telegram updates and pending reminder logs.';

    public function handle(ReminderDispatchService $dispatcher): int
    {
        $updates = $this->recoverTelegramUpdates();
        $reminders = $dispatcher->recoverStalePending();

        $this->info("Recovered {$updates} telegram update(s) and {$reminders} reminder log(s).");

        return self::SUCCESS;
    }

    private function recoverTelegramUpdates(): int
    {
        $threshold = now()->subMinutes((int) config('adhkar.update_stale_minutes', 5));
        $maxAttempts = (int) config('adhkar.update_max_attempts', 5);
        $recovered = 0;

        TelegramUpdate::query()
            ->whereIn('status', [TelegramUpdateStatus::Pending, TelegramUpdateStatus::Processing])
            ->where('received_at', '<=', $threshold)
            ->where('attempts', '<', $maxAttempts)
            ->orderBy('id')
            ->each(function (TelegramUpdate $update) use ($maxAttempts, &$recovered) {
                $update->increment('attempts');
                $update->refresh();

                if ($update->attempts >= $maxAttempts) {
                    $update->update([
                        'status' => TelegramUpdateStatus::Failed,
                        'error_message' => 'dispatch_exhausted_retries',
                    ]);

                    return;
                }

                ProcessTelegramUpdateJob::dispatch($update->id);
                $recovered++;
            });

        return $recovered;
    }
}
