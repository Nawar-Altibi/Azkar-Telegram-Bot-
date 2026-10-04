<?php

namespace App\Console\Commands;

use App\Services\FollowUpReminderService;
use Illuminate\Console\Command;

class SendFollowUpRemindersCommand extends Command
{
    protected $signature = 'adhkar:send-follow-ups';

    protected $description = 'Send the single second reminder to users who turned it on and have not opened their reminder after an hour.';

    public function handle(FollowUpReminderService $followUps): int
    {
        $this->info('Sent '.$followUps->sendDue().' follow-up reminders.');

        return self::SUCCESS;
    }
}
