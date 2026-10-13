<?php

namespace App\Jobs;

use App\Enums\ReminderLogStatus;
use App\Models\AdhkarCollection;
use App\Models\ReminderLog;
use App\Models\User;
use App\Services\AdhkarContentService;
use App\Services\ReminderCompletionService;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramClient;
use App\Support\Telegram\KeyboardFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
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

    public const QUEUE = 'reminders';

    public function __construct(public readonly int $reminderLogId)
    {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return (string) $this->reminderLogId;
    }

    public function handle(
        AdhkarContentService $content,
        TelegramClient $telegram,
        KeyboardFactory $keyboards,
        ReminderCompletionService $completion,
    ): void {
        $log = ReminderLog::query()->with('user')->findOrFail($this->reminderLogId);

        if (in_array($log->status, [ReminderLogStatus::Sent, ReminderLogStatus::Completed, ReminderLogStatus::Skipped], true)) {
            return;
        }

        if ($log->status === ReminderLogStatus::Failed && $this->mustNotRetry($log->error_message)) {
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

        if (! $this->reserveOutboundAttempt($log)) {
            return;
        }

        try {
            $direct = $user->sendsReadingWithReminder() ? $this->firstCard($log, $collection, $content, $keyboards) : null;
            $result = $direct !== null
                ? $telegram->sendMessage($user->chat_id, $direct['text'], $direct['markup'])
                : $telegram->sendMessage(
                    $user->chat_id,
                    $content->nudgeText($log->collection_key),
                    $keyboards->reminderNudge($log->id, $log->reminder_type->value),
                );

            $log->refresh();
            $log->update([
                'status' => ReminderLogStatus::Sent,
                'sent_at' => now(),
                'telegram_message_id' => [data_get($result, 'result.message_id')],
                'error_message' => null,
                ...($direct['snapshot'] ?? []),
            ]);
            if ($log->reminder_type !== null && $completion->alreadyFinished($user, $log->reminder_type)) {
                $completion->completeToday($user, $log->reminder_type);
            }
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
        if ($log === null || $log->status !== ReminderLogStatus::Pending) {
            return;
        }

        $log->update([
            'status' => ReminderLogStatus::Failed,
            'error_message' => $log->last_dispatch_attempt_at !== null
                ? 'nudge_unconfirmed'
                : Str::limit($exception?->getMessage() ?? 'job_failed', 1000),
            'retry_count' => $this->attempts(),
        ]);
    }

    /**
     * The reminder opens straight on the first card of the wird the user last chose, already claimed for reading.
     *
     * @return array{text:string,markup:array<string,mixed>,snapshot:array<string,mixed>}|null
     */
    private function firstCard(ReminderLog $log, AdhkarCollection $collection, AdhkarContentService $content, KeyboardFactory $keyboards): ?array
    {
        $key = (string) $log->collection_key;
        $phase = str_contains($key, '_full') ? 'a' : 's';
        $items = $content->itemsForPhase($key, $phase);
        if ($items->isEmpty()) {
            return null;
        }

        $total = $items->count();
        $actions = ['save' => 'sv:l:'.$log->id.':'.$phase];
        if ($total > 1) {
            $actions['next'] = 'rd:'.$log->id.':'.$phase.':1';
        } else {
            $actions['done'] = 'reminder:done:'.$log->id;
        }
        if (! $content->isSingleVersion($key)) {
            $target = $phase === 's' ? 'a' : 's';
            $actions[$target === 'a' ? 'switch_to_full' : 'switch_to_short'] = 'rsw:'.$log->id.':'.$target;
        }

        $lead = __(str_starts_with($key, 'evening') ? 'bot.direct_lead_evening' : 'bot.direct_lead_morning');
        $snapshot = $content->snapshotVersions($collection->items()->where('adhkar_items.active', true)->get());

        return [
            'text' => $lead."\n\n".$content->renderCard($key, $items[0], 1, $total, $total === 1),
            'markup' => $keyboards->readingNav($actions),
            'snapshot' => [
                'reading_started_at' => now(),
                'content_version_map' => $snapshot['content_version_map'],
                'content_snapshot_hash' => $snapshot['content_snapshot_hash'],
            ],
        ];
    }

    private function reserveOutboundAttempt(ReminderLog $log): bool
    {
        return (bool) DB::transaction(function () use ($log) {
            $locked = ReminderLog::query()->whereKey($log->id)->lockForUpdate()->first();
            if ($locked === null) {
                return false;
            }

            if (in_array($locked->status, [ReminderLogStatus::Sent, ReminderLogStatus::Completed, ReminderLogStatus::Skipped], true)) {
                return false;
            }

            if ($locked->status === ReminderLogStatus::Failed && $this->mustNotRetry($locked->error_message)) {
                return false;
            }

            if ($locked->status === ReminderLogStatus::Pending && $locked->last_dispatch_attempt_at !== null) {
                $locked->status = ReminderLogStatus::Failed;
                $locked->error_message = 'nudge_unconfirmed';
                $locked->save();

                return false;
            }

            $locked->last_dispatch_attempt_at = now();
            $locked->dispatch_attempts = (int) $locked->dispatch_attempts + 1;
            $locked->save();

            return true;
        });
    }

    private function mustNotRetry(?string $error): bool
    {
        return in_array($error, ['nudge_unconfirmed', 'blocked_by_user'], true);
    }

    private function markFailed(ReminderLog $log, User $user, TelegramApiException $exception): void
    {
        $blocked = $exception->isBlockedByUser();
        $log->update([
            'status' => ReminderLogStatus::Failed,
            'error_message' => $blocked ? 'blocked_by_user' : Str::limit($exception->getMessage(), 1000),
            'retry_count' => $this->attempts(),
        ]);

        if ($blocked) {
            $user->reminders_enabled = false;
            $user->next_morning_at = null;
            $user->next_evening_at = null;
            $user->save();
            Log::channel('telegram')->info('Disabled reminders after Telegram block', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
