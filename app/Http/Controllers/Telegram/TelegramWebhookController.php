<?php

namespace App\Http\Controllers\Telegram;

use App\Enums\TelegramUpdateStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\TelegramUpdate;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->all();
        if (! isset($payload['update_id'])) {
            return response()->json(['error' => 'invalid_update'], 400);
        }

        try {
            $record = DB::transaction(function () use ($payload) {
                return TelegramUpdate::query()->create([
                    'update_id' => $payload['update_id'],
                    'chat_id' => data_get($payload, 'message.chat.id')
                        ?? data_get($payload, 'callback_query.message.chat.id')
                        ?? data_get($payload, 'callback_query.from.id'),
                    'payload' => $payload,
                    'status' => TelegramUpdateStatus::Pending,
                    'received_at' => now(),
                ]);
            });

            ProcessTelegramUpdateJob::dispatch($record->id);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['ok' => true]);
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                return response()->json(['ok' => true]);
            }

            Log::channel('telegram')->error('Failed to persist telegram update', [
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['ok' => true]);
        }

        return response()->json(['ok' => true]);
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return (string) $exception->getCode() === '23000'
            || str_contains($exception->getMessage(), 'UNIQUE constraint failed')
            || str_contains($exception->getMessage(), 'Duplicate entry');
    }
}
