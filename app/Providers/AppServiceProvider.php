<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        App::setLocale('ar');

        RateLimiter::for('telegram-webhook', function (Request $request) {
            $chatId = $request->input('message.chat.id')
                ?? $request->input('callback_query.message.chat.id')
                ?? $request->ip();

            return Limit::perMinute(30)->by((string) $chatId);
        });
    }
}
