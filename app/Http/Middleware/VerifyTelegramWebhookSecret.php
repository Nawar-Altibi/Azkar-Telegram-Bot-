<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyTelegramWebhookSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('telegram.webhook_secret');
        if ($expected === '') {
            return response()->json(['error' => 'unauthorized'], 403);
        }

        $pathSecret = (string) $request->route('secret');
        $headerSecret = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        if (! hash_equals($expected, $pathSecret) || ! hash_equals($expected, $headerSecret)) {
            return response()->json(['error' => 'unauthorized'], 403);
        }

        return $next($request);
    }
}
