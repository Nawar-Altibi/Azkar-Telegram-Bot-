<?php

namespace App\Services;

use App\Enums\AdhkarMode;
use App\Enums\ReminderType;
use App\Models\GroupChat;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\WirdSender;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts the morning and evening wird as page images in groups at the times their admins chose.
 */
class GroupPostService
{
    public function __construct(
        private readonly AdhkarContentService $content,
        private readonly WirdSender $wirds,
    ) {}

    public function hasDue(): bool
    {
        return $this->due(now())->exists();
    }

    public function nextDueAt(): ?Carbon
    {
        $row = GroupChat::query()
            ->posting()
            ->toBase()
            ->selectRaw('min(next_morning_at) as morning, min(next_evening_at) as evening')
            ->first();

        $times = array_filter([$row->morning ?? null, $row->evening ?? null]);

        return $times === [] ? null : collect($times)->map(fn ($time) => Carbon::parse($time))->min();
    }

    /**
     * @param  float|null  $deadline  hrtime seconds after which no new group is posted to; the rest stay due for the next tick
     * @return int number of posts sent
     */
    public function postDue(?float $deadline = null): int
    {
        $now = now();
        $posted = 0;

        foreach ($this->due($now)->orderBy('id')->get() as $group) {
            foreach ([ReminderType::Morning, ReminderType::Evening] as $type) {
                if ($deadline !== null && hrtime(true) / 1e9 >= $deadline) {
                    return $posted;
                }

                $column = $type === ReminderType::Morning ? 'next_morning_at' : 'next_evening_at';
                $dueAt = $group->{$column};
                if ($dueAt === null || $dueAt->gt($now) || ! $this->claim($group, $type, $dueAt)) {
                    continue;
                }

                if ($this->post($group, $type)) {
                    $posted++;
                }
            }
        }

        return $posted;
    }

    /**
     * @return Builder<GroupChat>
     */
    private function due(Carbon $now): Builder
    {
        return GroupChat::query()
            ->posting()
            ->where(function ($query) use ($now) {
                $query->where('next_morning_at', '<=', $now)
                    ->orWhere('next_evening_at', '<=', $now);
            });
    }

    /**
     * Moves the post time to the next day first, so overlapping ticks never post twice.
     */
    private function claim(GroupChat $group, ReminderType $type, Carbon $dueAt): bool
    {
        $column = $type === ReminderType::Morning ? 'next_morning_at' : 'next_evening_at';
        $time = $type === ReminderType::Morning ? $group->morning_time : $group->evening_time;
        // From now, not from $dueAt, so a long outage yields one late post instead of one per missed day.
        $next = ReminderScheduleCalculator::calculateNext($group->timezone, $time, $dueAt->copy()->max(now()));

        $claimed = GroupChat::query()
            ->whereKey($group->id)
            ->where($column, $dueAt)
            ->update([$column => $next, 'updated_at' => now()]) === 1;

        if ($claimed) {
            $group->{$column} = $next;
        }

        return $claimed;
    }

    private function post(GroupChat $group, ReminderType $type, bool $retried = false): bool
    {
        $key = $this->content->collectionKey($group->mode ?? AdhkarMode::Short, $type);
        $items = $this->content->itemsForPhase($key, 's');
        if ($items->isEmpty()) {
            return false;
        }

        $caption = __('bot.group.post_caption', [
            'icon' => $type === ReminderType::Morning ? '🌅' : '🌇',
            'title' => e($this->content->wirdTitle($key)),
        ]);

        try {
            if (! $this->wirds->sendImages($group->chat_id, $key, $items, false, null, $caption)) {
                $this->wirds->sendPdf($group->chat_id, $key, $items, false, $caption);
            }

            return true;
        } catch (TelegramApiException $exception) {
            $migratedTo = data_get($exception->payload, 'parameters.migrate_to_chat_id');
            if (! $retried && is_numeric($migratedTo)) {
                $group->update(['chat_id' => (int) $migratedTo]);

                return $this->post($group, $type, true);
            }
            if ($exception->isBlockedByUser() || str_contains(mb_strtolower($exception->getMessage()), 'kicked')) {
                $group->bot_member = false;
                $group->reschedule();
                $group->save();
            }
            $this->logFailure($group, $exception);
        } catch (Throwable $exception) {
            $this->logFailure($group, $exception);
        }

        return false;
    }

    private function logFailure(GroupChat $group, Throwable $exception): void
    {
        Log::channel('telegram')->error('Group post failed', [
            'group_chat_id' => $group->id,
            'error' => TelegramClient::redact($exception->getMessage()),
        ]);
    }
}
