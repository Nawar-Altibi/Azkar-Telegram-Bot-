<?php

namespace App\Console\Commands;

use App\Services\AdminReportService;
use Illuminate\Console\Command;

class WeeklyReportCommand extends Command
{
    protected $signature = 'bot:weekly-report {--force : Send now even if this week\'s report already went out}';

    protected $description = 'Send the weekly usage report to TELEGRAM_ADMIN_CHAT_ID when it is due.';

    public function handle(AdminReportService $reports): int
    {
        if (config('telegram.admin_chat_id') === null) {
            $this->warn('TELEGRAM_ADMIN_CHAT_ID is not set.');

            return self::SUCCESS;
        }

        $this->info($reports->sendWeeklyIfDue((bool) $this->option('force')) ? 'Report sent.' : 'Not due yet.');

        return self::SUCCESS;
    }
}
