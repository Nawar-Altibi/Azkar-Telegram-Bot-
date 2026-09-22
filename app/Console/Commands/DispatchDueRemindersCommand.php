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
        $claimed = $dispatcher->claimDue();
        $recovered = $dispatcher->recoverStalePending();
        Cache::put('adhkar:last_scheduler_tick', now()->toIso8601String(), now()->addHour());

        $this->info("Claimed {$claimed} reminder(s); recovered {$recovered} stale pending log(s).");

        return self::SUCCESS;
    }
}
