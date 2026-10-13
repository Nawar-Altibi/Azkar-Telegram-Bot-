<?php

namespace App\Services;

use App\Enums\AdhkarMode;
use App\Enums\ReminderLogStatus;
use App\Enums\ReminderType;
use App\Models\ReadingSession;
use App\Models\ReminderLog;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use App\Support\DayPeriod;
use App\Support\Telegram\KeyboardFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One gentle second reminder, for users who turned it on and have not read that period's wird an hour later.
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
            // day moved on, or the extra hour after the period ended), so a skipped follow-up is closed too.
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

        // Any use of the bot since the reminder, such as opening settings, counts as seen.
        if ($user->last_interaction_at !== null && $log->sent_at !== null && $user->last_interaction_at->gt($log->sent_at)) {
            return false;
        }

        // Reading the same period from the buttons counts too, even when it happened before the reminder arrived.
        if ($this->readThisPeriodToday($log)) {
            return false;
        }

        $today = now()->timezone($user->timezone)->toDateString();
        $period = $log->reminder_type === ReminderType::Evening ? 'evening' : 'morning';

        return $log->scheduled_local_date?->toDateString() === $today
            && $this->withinFollowUpWindow($user, $period);
    }

    /**
     * The follow-up stays open through the period, and for one more hour after it ends,
     * so a reminder in the last hour still gets its second message.
     */
    private function withinFollowUpWindow(User $user, string $period): bool
    {
        if (DayPeriod::forUser($user) === $period) {
            return true;
        }

        $endHour = config("adhkar.day_windows.{$period}.1");
        if (! is_numeric($endHour)) {
            return false;
        }

        try {
            $local = now()->timezone($user->timezone);
        } catch (Throwable) {
            return false;
        }

        $end = $local->copy()->setTime((int) $endHour, 0);
        if ($local->lt($end)) {
            return false;
        }

        return $local->lt($end->copy()->addMinutes(self::DELAY_MINUTES));
    }

    /**
     * True when the user already opened this morning or evening wird on the reminder's local day.
     */
    private function readThisPeriodToday(ReminderLog $log): bool
    {
        $user = $log->user;
        if ($user === null || $user->timezone === null || $log->scheduled_local_date === null) {
            return false;
        }

        try {
            $start = Carbon::parse($log->scheduled_local_date->toDateString(), $user->timezone)->startOfDay();
        } catch (Throwable) {
            return false;
        }

        $prefix = $log->reminder_type === ReminderType::Evening ? 'evening' : 'morning';
        $from = $start->copy()->utc();
        $until = $start->copy()->addDay()->utc();

        return ReadingSession::query()
            ->where('user_id', $user->id)
            ->whereIn('collection_key', [$prefix.'_short', $prefix.'_full'])
            ->where('last_seen_at', '>=', $from)
            ->where('last_seen_at', '<', $until)
            ->exists();
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
