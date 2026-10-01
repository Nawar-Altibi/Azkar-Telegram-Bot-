<?php

namespace App\Console\Commands;

use App\Services\ReminderDispatchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class DispatchDueRemindersCommand extends Command
{
    protected $signature = 'adhkar:dispatch-due-reminders';

    protected $description = 'Claim due morning/evening reminders atomically and dispatch send jobs.';

    public function handle(ReminderDispatchService $dispatcher): int
    {
        // Stale recovery runs separately via adhkar:recover-stale (every five minutes).
        $claimed = $dispatcher->claimDue();
        Cache::put('adhkar:last_scheduler_tick', now()->toIso8601String(), now()->addHour());

        $this->info("Claimed {$claimed} reminder(s).");

        return self::SUCCESS;
    }
}
