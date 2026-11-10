<?php

namespace App\Services;

use App\Models\Broadcast;
use App\Models\BroadcastDelivery;
use App\Models\User;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class BroadcastService
{
    public function __construct(private readonly TelegramClient $telegram) {}

    public function enqueue(int $updateId, int $adminTelegramId, string $message): Broadcast
    {
        // Telegram may redeliver an update. A unique update ID makes broadcasts idempotent.
        $maxUserId = (int) User::query()->max('id');

        return Broadcast::query()->firstOrCreate(
            ['telegram_update_id' => $updateId],
            [
                'requested_by_telegram_id' => $adminTelegramId,
                'message' => $message,
                'max_user_id' => $maxUserId,
                'target_count' => User::query()->where('id', '<=', $maxUserId)->count(),
            ],
        );
    }

    /**
     * Called by the existing authenticated cron tick. Outbound attempts are recorded
     * BEFORE calling Telegram, so an uncertain network response is never retried blindly.
     *
     * @return array{broadcast_sent:int,broadcast_failed:int}
     */
    public function sendPending(?float $deadline = null): array
    {
        $stats = ['broadcast_sent' => 0, 'broadcast_failed' => 0];
        $limit = max(1, min(20, (int) config('telegram.broadcast_batch_size', 10)));
        $processed = 0;

        foreach (Broadcast::query()
            ->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('retry_at')->orWhere('retry_at', '<=', now()))
            ->orderBy('id')
            ->limit(5)
            ->get() as $broadcast) {
            while ($processed < $limit) {
                if ($deadline !== null && hrtime(true) / 1e9 >= $deadline) {
                    return $stats;
                }

                $user = User::query()
                    ->select(['id', 'chat_id'])
                    ->where('id', '>', $broadcast->last_user_id)
                    ->where('id', '<=', $broadcast->max_user_id)
                    ->orderBy('id')
                    ->first();

                if ($user === null) {
                    $broadcast->update(['status' => 'completed']);
                    break;
                }

                $previousUserId = $broadcast->last_user_id;
                $delivery = $this->reserveAttempt($broadcast, $user);
                $broadcast->refresh();
                if ($delivery === null) {
                    continue;
                }

                try {
                    // TelegramClient uses HTML parse mode. Escape user-authored text.
                    $this->telegram->sendMessage($user->chat_id, e($broadcast->message));
                    $delivery->update(['status' => 'sent', 'sent_at' => now()]);
                    $broadcast->increment('sent_count');
                    $stats['broadcast_sent']++;
                } catch (TelegramApiException $exception) {
                    // A 429 is an explicit rejection, not an uncertain send:
                    // restore the cursor and retry after Telegram's cooldown.
                    if ($exception->httpStatus === 429) {
                        $this->deferRateLimited($broadcast, $user, $delivery, $previousUserId, $exception);

                        return $stats;
                    }

                    $delivery->update([
                        'status' => 'failed',
                        'error_message' => Str::limit(TelegramClient::redact($exception->getMessage()), 500),
                    ]);
                    $broadcast->increment('failed_count');
                    $stats['broadcast_failed']++;

                } catch (Throwable $exception) {
                    $delivery->update([
                        'status' => 'unconfirmed',
                        'error_message' => Str::limit(TelegramClient::redact($exception->getMessage()), 500),
                    ]);
                    $broadcast->increment('failed_count');
                    $stats['broadcast_failed']++;
                    Log::channel('telegram')->warning('Broadcast delivery outcome uncertain', [
                        'broadcast_id' => $broadcast->id,
                        'user_id' => $user->id,
                    ]);
                }

                $processed++;
                $broadcast->refresh();
                usleep(max(0, (int) config('telegram.broadcast_delay_microseconds', 100_000)));
            }

            if ($processed >= $limit) {
                break;
            }
        }

        return $stats;
    }

    private function deferRateLimited(
        Broadcast $broadcast,
        User $user,
        BroadcastDelivery $delivery,
        int $previousUserId,
        TelegramApiException $exception,
    ): void {
        DB::transaction(function () use ($broadcast, $user, $delivery, $previousUserId, $exception) {
            $locked = Broadcast::query()->whereKey($broadcast->id)->lockForUpdate()->firstOrFail();

            if ($locked->last_user_id !== $user->id) {
                return;
            }

            $locked->last_user_id = $previousUserId;
            $locked->retry_at = now()->addSeconds(
                max(1, (int) data_get($exception->payload, 'parameters.retry_after', 60))
            );
            $locked->save();
            $delivery->delete();
        });
    }

    private function reserveAttempt(Broadcast $broadcast, User $user): ?BroadcastDelivery
    {
        return DB::transaction(function () use ($broadcast, $user) {
            $locked = Broadcast::query()->whereKey($broadcast->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== 'pending' || $user->id <= $locked->last_user_id) {
                return null;
            }

            $delivery = BroadcastDelivery::query()->firstOrCreate(
                ['broadcast_id' => $locked->id, 'user_id' => $user->id],
                [
                    'chat_id' => $user->chat_id,
                    'status' => 'attempting',
                    'attempted_at' => now(),
                ],
            );

            // Advance in the same transaction as the delivery reservation.
            $locked->last_user_id = $user->id;
            $locked->save();

            return $delivery->wasRecentlyCreated ? $delivery : null;
        });
    }
}
