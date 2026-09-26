<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramUpdateProcessor;
use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;

class PollTelegramCommand extends Command
{
    protected $signature = 'telegram:poll {--sleep=1}';

    protected $description = 'Long-poll Telegram getUpdates and process them synchronously (local development).';

    public function handle(TelegramClient $client, TelegramUpdateProcessor $processor): int
    {
        $this->info('Polling Telegram updates. Ctrl+C to stop.');
        $offset = 0;

        while (true) {
            try {
                $updates = $client->getUpdates($offset, 25);
                foreach ($updates as $update) {
                    $offset = ((int) ($update['update_id'] ?? 0)) + 1;
                    $processor->handle($update);
                }
            } catch (\Throwable $e) {
                $this->warn('Poll error: '.$e->getMessage());
                sleep(max(2, (int) $this->option('sleep')));

                continue;
            }

            sleep((int) $this->option('sleep'));
        }
    }
}
