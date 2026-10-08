<?php

namespace App\Services;

use App\Models\ReadingSession;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tracks how far each opened wird got, for the owner's report. Recording must never break a reply.
 */
class ReadingSessionRecorder
{
    // Switching short/full on the first card replaces the session it just opened instead of counting a drop-off.
    private const SWITCH_SECONDS = 120;

    // Next/previous on an older message continues its session only within this window.
    private const CONTINUE_HOURS = 12;

    /**
     * @param  int  $index  zero-based card just shown
     */
    public function record(
        User $user,
        string $collectionKey,
        string $phase,
        string $source,
        ?int $reminderLogId,
        int $index,
        int $total,
        bool $opened,
    ): void {
        try {
            $session = $opened
                ? $this->switchedSession($user, $collectionKey)
                : $this->continuedSession($user, $collectionKey, $phase, $reminderLogId);

            $reached = $index + 1;
            if ($session === null) {
                $session = new ReadingSession($this->localTime($user) + [
                    'user_id' => $user->id,
                    'started_at' => now(),
                    'furthest' => $reached,
                    'source' => $source,
                ]);
            } elseif ($opened) {
                $session->furthest = $reached;
            } else {
                $session->furthest = max((int) $session->furthest, $reached);
            }

            $session->collection_key = $collectionKey;
            $session->phase = $phase;
            $session->total = $total;
            $session->reminder_log_id ??= $reminderLogId;
            $session->last_seen_at = now();
            if ($session->completed_at === null && $session->furthest >= $total) {
                $session->completed_at = now();
            }
            $session->save();
        } catch (Throwable $exception) {
            Log::warning('Reading session not recorded', ['error' => $exception->getMessage()]);
        }
    }

    private function switchedSession(User $user, string $collectionKey): ?ReadingSession
    {
        $latest = ReadingSession::query()
            ->where('user_id', $user->id)
            ->where('started_at', '>=', now()->subSeconds(self::SWITCH_SECONDS))
            ->latest('id')
            ->first();

        return $latest !== null
            && $latest->completed_at === null
            && (int) $latest->furthest <= 1
            && self::group($latest->collection_key) === self::group($collectionKey)
            ? $latest
            : null;
    }

    private function continuedSession(User $user, string $collectionKey, string $phase, ?int $reminderLogId): ?ReadingSession
    {
        return ReadingSession::query()
            ->where('user_id', $user->id)
            ->where('collection_key', $collectionKey)
            ->where('phase', $phase)
            ->where('last_seen_at', '>=', now()->subHours(self::CONTINUE_HOURS))
            ->when($reminderLogId !== null, fn ($query) => $query->where('reminder_log_id', $reminderLogId))
            ->latest('id')
            ->first();
    }

    /**
     * morning, evening, bedtime, waking, after_prayer...
     */
    public static function group(string $collectionKey): string
    {
        return (string) preg_replace('/_(short|full)$/', '', $collectionKey);
    }

    /**
     * @return array{local_hour?:int,local_weekday?:int}
     */
    private function localTime(User $user): array
    {
        try {
            $local = now()->timezone($user->timezone ?: 'UTC');
        } catch (Throwable) {
            return [];
        }

        return ['local_hour' => $local->hour, 'local_weekday' => $local->dayOfWeek];
    }
}
