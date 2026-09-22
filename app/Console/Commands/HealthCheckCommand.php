<?php

namespace App\Console\Commands;

use App\Enums\ReminderLogStatus;
use App\Enums\TelegramUpdateStatus;
use App\Models\AdhkarItem;
use App\Models\ReminderLog;
use App\Models\TelegramUpdate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HealthCheckCommand extends Command
{
    protected $signature = 'adhkar:health';

    protected $description = 'Report scheduler, queue, reminder, and telegram inbox health.';

    public function handle(): int
    {
        $lastTick = Cache::get('adhkar:last_scheduler_tick', 'never');
        $failedJobs = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        $pendingReminders = ReminderLog::query()->where('status', ReminderLogStatus::Pending)->count();
        $exhaustedReminders = ReminderLog::query()
            ->where('status', ReminderLogStatus::Failed)
            ->where('error_message', 'dispatch_exhausted_retries')
            ->count();
        $stuckUpdates = TelegramUpdate::query()
            ->whereIn('status', [TelegramUpdateStatus::Pending, TelegramUpdateStatus::Processing, TelegramUpdateStatus::Failed])
            ->count();
        $items = AdhkarItem::query()->where('active', true)->count();

        $this->table(['check', 'value'], [
            ['last_scheduler_tick', $lastTick],
            ['failed_jobs', $failedJobs],
            ['pending_reminder_logs', $pendingReminders],
            ['dispatch_exhausted_retries', $exhaustedReminders],
            ['stuck_telegram_updates', $stuckUpdates],
            ['active_adhkar_items', $items],
        ]);

        return self::SUCCESS;
    }
}
