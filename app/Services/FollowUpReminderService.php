<?php

namespace App\Services;

use App\Enums\AdhkarMode;
use App\Enums\ReminderLogStatus;
use App\Enums\ReminderType;
use App\Models\ReminderLog;
use App\Services\Telegram\TelegramClient;
use App\Support\DayPeriod;
use App\Support\Telegram\KeyboardFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One gentle second reminder, for users who turned it on and have not opened the reminder an hour later.
 */
class FollowUpReminderService
{
    public const DELAY_MINUTES = 60;

    private const LOOKBACK_HOURS = 6;

    private const BATCH = 200;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly KeyboardFactory $keyboards,
        private readonly BotEventRecorder $events,
    ) {}

    public function sendDue(): int
    {
        $sent = 0;
        $logs = $this->open()
            ->with('user')
            ->where('sent_at', '<=', now()->subMinutes(self::DELAY_MINUTES))
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get();

        foreach ($logs as $log) {
            // Every reason to skip is final once the hour has passed (the user already came back, or the
            // day or period moved on), so a skipped follow-up is closed too and never re-checked.
            if (! $this->shouldSend($log)) {
                $this->claim($log);

                continue;
            }

            if ($this->claim($log)) {
                $sent += $this->send($log) ? 1 : 0;
            }
        }

        return $sent;
    }

    /**
     * When the oldest open follow-up becomes due, or null when none is open.
     */
    public function nextDueAt(): ?Carbon
    {
        $sentAt = $this->open()->min('sent_at');

        return $sentAt === null ? null : Carbon::parse($sentAt)->addMinutes(self::DELAY_MINUTES);
    }

    /**
     * @return Builder<ReminderLog>
     */
    private function open(): Builder
    {
        return ReminderLog::query()
            ->where('status', ReminderLogStatus::Sent)
            ->whereNull('reading_started_at')
            ->whereNull('completed_at')
            ->whereNull('follow_up_sent_at')
            ->where('sent_at', '>=', now()->subHours(self::LOOKBACK_HOURS))
            ->whereHas('user', fn ($query) => $query->where('follow_up_enabled', true)->where('reminders_enabled', true));
    }

    private function shouldSend(ReminderLog $log): bool
    {
        $user = $log->user;
        if ($user === null || ! $user->timezone) {
            return false;
        }

        // Any use of the bot since the reminder, such as reading the same adhkar from the menu, counts as seen.
        if ($user->last_interaction_at !== null && $log->sent_at !== null && $user->last_interaction_at->gt($log->sent_at)) {
            return false;
        }

        $today = now()->timezone($user->timezone)->toDateString();
        $period = $log->reminder_type === ReminderType::Evening ? 'evening' : 'morning';

        return $log->scheduled_local_date?->toDateString() === $today
            && DayPeriod::forUser($user) === $period;
    }

    private function claim(ReminderLog $log): bool
    {
        return ReminderLog::query()
            ->whereKey($log->id)
            ->whereNull('follow_up_sent_at')
            ->whereNull('reading_started_at')
            ->update(['follow_up_sent_at' => now()]) === 1;
    }

    private function send(ReminderLog $log): bool
    {
        $user = $log->user;
        $evening = $log->reminder_type === ReminderType::Evening;
        $remembered = $user->rememberedMode();

        try {
            $this->telegram->sendMessage(
                $user->chat_id,
                __($evening ? 'bot.follow_up_evening' : 'bot.follow_up_morning'),
                $this->keyboards->followUp($log->id, $remembered === null ? null : ($remembered === AdhkarMode::Full ? 'a' : 's')),
            );
        } catch (Throwable $exception) {
            Log::channel('telegram')->warning('Follow-up reminder failed', [
                'reminder_log_id' => $log->id,
                'error' => TelegramClient::redact($exception->getMessage()),
            ]);

            return false;
        }

        $this->events->record($user, 'follow_up_sent', ['type' => $log->reminder_type?->value]);

        return true;
    }
}
