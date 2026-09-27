<?php

use App\Http\Controllers\Internal\SchedulerCronController;
use App\Http\Controllers\Telegram\TelegramWebhookController;
use App\Http\Middleware\VerifyCronSecret;
use App\Http\Middleware\VerifyTelegramWebhookSecret;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::post('/telegram/webhook/{secret}', TelegramWebhookController::class)
                ->middleware([VerifyTelegramWebhookSecret::class, 'throttle:telegram-webhook'])
                ->name('telegram.webhook');

            Route::post('/internal/cron', SchedulerCronController::class)
                ->middleware(VerifyCronSecret::class)
                ->name('internal.cron');
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
