<?php

namespace App\Jobs;

use App\Enums\ReminderLogStatus;
use App\Models\AdhkarCollection;
use App\Models\ReminderLog;
use App\Models\User;
use App\Services\AdhkarContentService;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramClient;
use App\Support\Telegram\KeyboardFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SendAdhkarReminderJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 30, 120];

    public int $uniqueFor = 180;

    public function __construct(public readonly int $reminderLogId) {}

    public function uniqueId(): string
    {
        return (string) $this->reminderLogId;
    }

    public function handle(
        AdhkarContentService $content,
        TelegramClient $telegram,
        KeyboardFactory $keyboards,
    ): void {
        $log = ReminderLog::query()->with('user')->findOrFail($this->reminderLogId);

        if (in_array($log->status, [ReminderLogStatus::Sent, ReminderLogStatus::Completed], true)) {
            return;
        }

        $user = $log->user;
        $collection = AdhkarCollection::query()
            ->where('key', $log->collection_key)
            ->where('active', true)
            ->first();

        if ($user === null || $collection === null) {
            $log->update([
                'status' => ReminderLogStatus::Failed,
                'error_message' => 'missing_user_or_collection',
            ]);

            return;
        }

        $items = $collection->items()->where('adhkar_items.active', true)->get();
        $snapshot = $content->snapshotVersions($items);
        $messages = $content->buildMessages($collection);
        $messageIds = [];

        try {
            foreach ($messages as $index => $message) {
                $markup = [];
                $isLast = $index === array_key_last($messages);
                if ($isLast) {
                    $markup = $keyboards->completion($log->id);
                }

                $result = $telegram->sendMessage($user->chat_id, $message, $markup);
                $messageIds[] = data_get($result, 'result.message_id');

                if (! $isLast) {
                    usleep((int) config('adhkar.inter_message_delay_microseconds', 50_000));
                }
            }

            $log->update([
                'status' => ReminderLogStatus::Sent,
                'sent_at' => now(),
                'telegram_message_id' => $messageIds,
                'content_version_map' => $snapshot['content_version_map'],
                'content_snapshot_hash' => $snapshot['content_snapshot_hash'],
                'error_message' => null,
            ]);
        } catch (TelegramApiException $exception) {
            $this->markFailed($log, $user, $exception);
            if (! $exception->isBlockedByUser()) {
                throw $exception;
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        $log = ReminderLog::query()->find($this->reminderLogId);
        if ($log && $log->status === ReminderLogStatus::Pending) {
            $log->update([
                'status' => ReminderLogStatus::Failed,
                'error_message' => Str::limit($exception?->getMessage() ?? 'job_failed', 1000),
                'retry_count' => $this->attempts(),
            ]);
        }
    }

    private function markFailed(ReminderLog $log, User $user, TelegramApiException $exception): void
    {
        $log->update([
            'status' => ReminderLogStatus::Failed,
            'error_message' => Str::limit($exception->getMessage(), 1000),
            'retry_count' => $this->attempts(),
        ]);

        if ($exception->isBlockedByUser()) {
            $user->reminders_enabled = false;
            $user->next_morning_at = null;
            $user->next_evening_at = null;
            $user->save();
            Log::channel('telegram')->info('Disabled reminders after Telegram block', [
                'user_id' => $user->id,
            ]);
        }
    }
}
