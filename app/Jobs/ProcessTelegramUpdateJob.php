<?php

namespace App\Jobs;

use App\Enums\TelegramUpdateStatus;
use App\Models\TelegramUpdate;
use App\Services\Telegram\TelegramUpdateProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class ProcessTelegramUpdateJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 30, 120];

    public int $uniqueFor = 120;

    public function __construct(public readonly int $telegramUpdateId) {}

    public function uniqueId(): string
    {
        return (string) $this->telegramUpdateId;
    }

    public function handle(TelegramUpdateProcessor $processor): void
    {
        $record = TelegramUpdate::query()->findOrFail($this->telegramUpdateId);
        $record->update(['status' => TelegramUpdateStatus::Processing]);

        try {
            $processor->handle($record->payload ?? []);
            $record->update([
                'status' => TelegramUpdateStatus::Processed,
                'processed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $record->update([
                'status' => TelegramUpdateStatus::Failed,
                'attempts' => $record->attempts + 1,
                'error_message' => Str::limit($exception->getMessage(), 1000),
            ]);

            throw $exception;
        }
    }
}
