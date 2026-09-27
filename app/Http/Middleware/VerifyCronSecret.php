<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyCronSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.cron.secret');
        $provided = (string) $request->bearerToken();

        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['error' => 'unauthorized'], 403);
        }

        return $next($request);
    }
}
