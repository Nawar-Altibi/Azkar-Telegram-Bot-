<?php

namespace App\Console\Commands;

use App\Services\BotEventRecorder;
use Illuminate\Console\Command;

class PruneBotEventsCommand extends Command
{
    protected $signature = 'bot:prune-events';

    protected $description = 'Delete unclear messages users typed once they are older than 30 days.';

    public function handle(BotEventRecorder $recorder): int
    {
        $deleted = $recorder->pruneUnknownText();
        $this->info("Deleted {$deleted} old unclear messages.");

        return self::SUCCESS;
    }
}
