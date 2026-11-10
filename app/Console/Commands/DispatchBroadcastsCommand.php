<?php

namespace App\Console\Commands;

use App\Services\BroadcastService;
use Illuminate\Console\Command;

class DispatchBroadcastsCommand extends Command
{
    protected $signature = 'adhkar:dispatch-broadcasts';

    protected $description = 'Deliver a bounded batch of pending admin broadcast messages.';

    public function handle(BroadcastService $broadcasts): int
    {
        $counts = $broadcasts->sendPending();
        $this->info("Broadcast: {$counts['broadcast_sent']} sent; {$counts['broadcast_failed']} failed.");

        return self::SUCCESS;
    }
}
