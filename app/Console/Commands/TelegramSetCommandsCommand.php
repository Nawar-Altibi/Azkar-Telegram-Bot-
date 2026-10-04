<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;

class TelegramSetCommandsCommand extends Command
{
    protected $signature = 'telegram:commands:set';

    protected $description = 'Register the bot commands menu with Arabic descriptions.';

    public function handle(TelegramClient $client): int
    {
        $commands = collect(__('bot.commands'))
            ->map(fn (string $description, string $command) => [
                'command' => $command,
                'description' => $description,
            ])
            ->values()
            ->all();

        // Telegram shows the default list to everyone whose app language has no list of its own.
        $client->setMyCommands($commands);
        $client->setMyCommands($commands, 'ar');
        $this->info('Registered '.count($commands).' commands.');

        return self::SUCCESS;
    }
}
