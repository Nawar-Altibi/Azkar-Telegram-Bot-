<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;

class TelegramSetWebhookCommand extends Command
{
    protected $signature = 'telegram:webhook:set
                            {--unset : Remove the currently registered webhook}';

    protected $description = 'Register or remove the Telegram webhook URL.';

    public function handle(TelegramClient $client): int
    {
        if ($this->option('unset')) {
            $client->deleteWebhook();
            $this->info('Webhook removed.');

            return self::SUCCESS;
        }

        $secret = (string) config('telegram.webhook_secret');
        $configured = (string) config('telegram.webhook_url');
        $url = $configured !== ''
            ? $configured
            : rtrim((string) config('app.url'), '/').'/telegram/webhook/'.$secret;

        if ($secret === '') {
            $this->error('TELEGRAM_WEBHOOK_SECRET is not set.');

            return self::FAILURE;
        }

        $client->setWebhook($url, $secret);
        $this->info("Webhook registered: {$url}");

        return self::SUCCESS;
    }
}
