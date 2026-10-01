<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Services\CronTickService;
use App\Services\Telegram\TelegramClient;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PDOException;
use Throwable;

class SchedulerCronController extends Controller
{
    public function __invoke(CronTickService $tick): JsonResponse
    {
        try {
            $tick->run();
        } catch (Throwable $exception) {
            // Database messages can echo the DSN or row values, so only their class and SQLSTATE are logged.
            $database = $exception instanceof QueryException || $exception instanceof PDOException;
            Log::error('cron_tick_failed', [
                'exception' => $exception::class,
                'code' => (string) $exception->getCode(),
                'error' => $database ? null : Str::limit(TelegramClient::redact($exception->getMessage()), 500),
            ]);

            return response()->json(['ok' => false], 500);
        }

        return response()->json(['ok' => true]);
    }
}
