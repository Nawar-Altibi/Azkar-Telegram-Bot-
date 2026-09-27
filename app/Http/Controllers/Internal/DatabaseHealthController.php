<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Support\DatabaseStatus;
use Illuminate\Http\JsonResponse;

class DatabaseHealthController extends Controller
{
    public function __invoke(DatabaseStatus $status): JsonResponse
    {
        try {
            return response()->json(['ok' => true] + $status->report());
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => $e::class,
                'code' => (string) $e->getCode(),
            ], 503);
        }
    }
}
