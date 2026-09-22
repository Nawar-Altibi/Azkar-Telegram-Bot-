<?php

namespace App\Console\Commands;

use App\Models\AdhkarItem;
use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class SmokeTestCommand extends Command
{
    protected $signature = 'adhkar:smoke
                            {--live : Call Telegram getMe/getWebhookInfo (requires TELEGRAM_BOT_TOKEN)}';

    protected $description = 'Verify deploy preconditions. A live /start reply still requires a running queue worker.';

    public function handle(TelegramClient $client): int
    {
        $failed = false;

        $failed = $this->check('database tables', function () {
            foreach (['users', 'telegram_updates', 'reminder_logs', 'adhkar_items', 'jobs'] as $table) {
                if (! Schema::hasTable($table)) {
                    return "missing table {$table}";
                }
            }

            return true;
        }) || $failed;

        $failed = $this->check('active adhkar items', function () {
            $count = AdhkarItem::query()->where('active', true)->count();

            return $count > 0 ? true : 'no active items — run php artisan adhkar:sync';
        }) || $failed;

        $failed = $this->check('webhook route', function () {
            return Route::has('telegram.webhook') ? true : 'telegram.webhook route missing';
        }) || $failed;

        $failed = $this->check('GET /up', function () {
            $found = collect(Route::getRoutes())->contains(
                fn ($route) => $route->uri() === 'up' && in_array('GET', $route->methods(), true)
            );

            return $found ? true : '/up health route missing';
        }) || $failed;

        $failed = $this->check('scheduler commands', function () {
            $events = collect(app(Schedule::class)->events())
                ->map(fn ($event) => (string) ($event->command ?? $event->description ?? ''));

            $hasDispatch = $events->contains(fn ($command) => str_contains($command, 'adhkar:dispatch-due-reminders'));
            $hasRecover = $events->contains(fn ($command) => str_contains($command, 'adhkar:recover-stale'));

            return $hasDispatch && $hasRecover
                ? true
                : 'schedule is missing dispatch or recover commands';
        }) || $failed;

        $secret = (string) config('telegram.webhook_secret');
        $failed = $this->check('TELEGRAM_WEBHOOK_SECRET', function () use ($secret) {
            return $secret !== '' ? true : 'not set';
        }) || $failed;

        $tokenSet = (string) config('telegram.bot_token') !== '';
        $this->line('TELEGRAM_BOT_TOKEN: '.($tokenSet ? 'set' : 'missing'));

        if (! $this->option('live')) {
            $this->newLine();
            $this->comment('Preflight complete. A real /start reply still needs:');
            $this->comment('1. TELEGRAM_BOT_TOKEN and HTTPS APP_URL');
            $this->comment('2. php artisan queue:work --tries=3 --backoff=5,30,120');
            $this->comment('3. php artisan telegram:webhook:set');
            $this->comment('4. Send /start in Telegram and confirm a reply (webhook 200 is not enough)');

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        if (! $tokenSet) {
            $this->error('Cannot run --live without TELEGRAM_BOT_TOKEN.');

            return self::FAILURE;
        }

        try {
            $me = $client->getMe();
            $username = $me['result']['username'] ?? 'unknown';
            $this->info("Telegram getMe: @{$username}");
        } catch (\Throwable $e) {
            $this->error('Telegram getMe failed: '.$e->getMessage());

            return self::FAILURE;
        }

        try {
            $info = $client->getWebhookInfo();
            $url = $info['result']['url'] ?? '';
            $this->info('Webhook URL: '.($url !== '' ? $url : '(not registered)'));
            if ($url !== '' && ! str_starts_with($url, 'https://')) {
                $this->error('Webhook URL must be HTTPS.');

                return self::FAILURE;
            }
        } catch (\Throwable $e) {
            $this->error('Telegram getWebhookInfo failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->comment('Bot API is reachable. Confirm a real /start reply while the queue worker is running.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  callable(): (true|string)  $callback
     */
    private function check(string $label, callable $callback): bool
    {
        $result = $callback();
        if ($result === true) {
            $this->info("ok  {$label}");

            return false;
        }

        $this->error("fail {$label}: {$result}");

        return true;
    }
}
