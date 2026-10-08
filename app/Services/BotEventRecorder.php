<?php

namespace App\Services;

use App\Models\BotEvent;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Usage events for the weekly report. Recording must never break a reply to the user.
 */
class BotEventRecorder
{
    public const UNKNOWN_TEXT = 'unknown_text';

    public const UNKNOWN_TEXT_RETENTION_DAYS = 30;

    /**
     * @param  array<string, mixed>  $meta
     */
    public function record(?User $user, string $event, array $meta = []): void
    {
        try {
            BotEvent::query()->create([
                'user_id' => $user?->id,
                'event' => $event,
                'meta' => $meta === [] ? null : $meta,
                'created_at' => now(),
            ]);

            if ($event === self::UNKNOWN_TEXT) {
                $this->pruneUnknownText();
            }
        } catch (Throwable $exception) {
            Log::warning('Bot event not recorded', ['event' => $event, 'error' => $exception->getMessage()]);
        }
    }

    public function unknownText(User $user, string $text): void
    {
        $this->record($user, self::UNKNOWN_TEXT, ['text' => Str::limit(trim($text), 80)]);
    }

    /**
     * What users typed is personal, so it is kept only long enough to improve the keyword list.
     */
    public function pruneUnknownText(): int
    {
        return BotEvent::query()
            ->where('event', self::UNKNOWN_TEXT)
            ->where('created_at', '<', now()->subDays(self::UNKNOWN_TEXT_RETENTION_DAYS))
            ->delete();
    }

    public function hasRecorded(User $user, string $event): bool
    {
        return BotEvent::query()->where('user_id', $user->id)->where('event', $event)->exists();
    }

    /**
     * Seconds since this user's last pause, or null when there was none.
     */
    public function secondsSinceLastPause(User $user): ?int
    {
        $pausedAt = BotEvent::query()
            ->where('user_id', $user->id)
            ->where('event', 'paused')
            ->latest('created_at')
            ->value('created_at');

        return $pausedAt === null ? null : (int) abs(now()->diffInSeconds($pausedAt));
    }
}
