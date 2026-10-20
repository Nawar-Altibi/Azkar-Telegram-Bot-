<?php

namespace App\Services;

use App\Models\AdhkarCompletion;
use App\Models\User;
use Throwable;

/**
 * Stores the user's daily acknowledgement independently from the delivery format.
 */
class AdhkarCompletionService
{
    /**
     * Additional adhkar are an optional library, not a daily wird acknowledgement.
     *
     * @var list<string>
     */
    private const SUPPORTED_GROUPS = [
        'morning',
        'evening',
        'bedtime',
        'waking',
        'after_prayer',
    ];

    public function groupForCollection(string $collectionKey): ?string
    {
        $group = preg_replace('/_(short|full)$/', '', $collectionKey);
        if (! is_string($group) || ! in_array($group, self::SUPPORTED_GROUPS, true)) {
            return null;
        }

        return $group;
    }

    public function isCompleted(User $user, string $collectionKey): bool
    {
        $group = $this->groupForCollection($collectionKey);
        $date = $this->localDate($user);
        if ($group === null || $date === null) {
            return false;
        }

        return AdhkarCompletion::query()
            ->where('user_id', $user->id)
            ->where('collection_group', $group)
            ->whereDate('local_date', $date)
            ->exists();
    }

    public function complete(User $user, string $collectionKey): bool
    {
        $group = $this->groupForCollection($collectionKey);
        $date = $this->localDate($user);
        if ($group === null || $date === null) {
            return false;
        }

        return AdhkarCompletion::query()->insertOrIgnore([
            'user_id' => $user->id,
            'collection_group' => $group,
            'local_date' => $date,
            'completed_at' => now(),
        ]) > 0;
    }

    public function confirmationText(string $collectionKey, bool $newlyCompleted = true): string
    {
        if (! $newlyCompleted) {
            return __('bot.already_completed');
        }

        return match ($this->groupForCollection($collectionKey)) {
            'morning' => __('bot.completed_morning'),
            'evening' => __('bot.completed_evening'),
            'bedtime' => __('bot.completed_bedtime'),
            'waking' => __('bot.completed_waking'),
            'after_prayer' => __('bot.completed_after_prayer'),
            default => __('bot.already_completed'),
        };
    }

    private function localDate(User $user): ?string
    {
        if (! filled($user->timezone)) {
            return null;
        }

        try {
            return now()->timezone($user->timezone)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
