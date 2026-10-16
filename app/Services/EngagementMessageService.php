<?php

namespace App\Services;

use App\Enums\OnboardingState;
use App\Enums\ReminderLogStatus;
use App\Enums\ReminderType;
use App\Models\Feedback;
use App\Models\ReadingSession;
use App\Models\ReminderLog;
use App\Models\User;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Soft private-chat messages that help people stay with the bot: time fit, weekly summary, comeback, feedback invite.
 */
class EngagementMessageService
{
    private const BATCH = 40;

    private const FEEDBACK_DAILY_CAP = 30;

    private const TIME_FIT_COOLDOWN_DAYS = 30;

    private const COMEBACK_AFTER_DAYS = 14;

    private const COMEBACK_COOLDOWN_DAYS = 90;

    private const FEEDBACK_MIN_DAYS = 7;

    private const FEEDBACK_MIN_SESSIONS = 2;

    private const FEEDBACK_IGNORE_DAYS = 90;

    private const FEEDBACK_AFTER_SEND_DAYS = 180;

    private const WEEKLY_SUMMARY_INTERVAL_DAYS = 7;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly BotEventRecorder $events,
    ) {}

    /**
     * @param  float|null  $deadline  hrtime seconds
     * @return array{time_fit:int,weekly:int,comeback:int,feedback:int}
     */
    public function sendDue(?float $deadline = null): array
    {
        $counts = ['time_fit' => 0, 'weekly' => 0, 'comeback' => 0, 'feedback' => 0];
        $counts['weekly'] = $this->sendWeeklySummaries($deadline);
        if ($this->overBudget($deadline)) {
            return $counts;
        }
        $counts['time_fit'] = $this->sendTimeFitAsks($deadline);
        if ($this->overBudget($deadline)) {
            return $counts;
        }
        $counts['comeback'] = $this->sendComebacks($deadline);
        if ($this->overBudget($deadline)) {
            return $counts;
        }
        $counts['feedback'] = $this->sendFeedbackNudges($deadline);

        return $counts;
    }

    private function overBudget(?float $deadline): bool
    {
        return $deadline !== null && hrtime(true) / 1e9 >= $deadline;
    }

    private function sendWeeklySummaries(?float $deadline): int
    {
        $sent = 0;
        $weekAgo = now()->subDays(self::WEEKLY_SUMMARY_INTERVAL_DAYS);
        $users = User::query()
            ->where('onboarding_state', OnboardingState::Completed)
            ->where('weekly_summary_enabled', true)
            ->where('reminders_enabled', true)
            ->whereNotNull('timezone')
            ->where(function ($query) use ($weekAgo) {
                $query->where(function ($first) use ($weekAgo) {
                    $first->whereNull('last_weekly_summary_at')
                        ->where('created_at', '<=', $weekAgo);
                })->orWhere('last_weekly_summary_at', '<=', $weekAgo);
            })
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get();

        foreach ($users as $user) {
            if ($this->overBudget($deadline)) {
                break;
            }
            if (! $this->isWeeklyDue($user)) {
                continue;
            }
            if ($this->sendWeekly($user)) {
                $sent++;
            }
        }

        return $sent;
    }

    private function isWeeklyDue(User $user): bool
    {
        try {
            $local = now($user->timezone);
        } catch (Throwable) {
            return false;
        }

        // Soft daytime window so summaries do not land at night.
        if ($local->hour < 10 || $local->hour >= 14) {
            return false;
        }

        if ($user->last_weekly_summary_at !== null) {
            return $user->last_weekly_summary_at->lte(now()->subDays(self::WEEKLY_SUMMARY_INTERVAL_DAYS));
        }

        // First summary: only after the user has been on the bot for a full week.
        return $user->created_at !== null
            && $user->created_at->lte(now()->subDays(self::WEEKLY_SUMMARY_INTERVAL_DAYS));
    }

    private function sendWeekly(User $user): bool
    {
        try {
            $local = now($user->timezone);
        } catch (Throwable) {
            return false;
        }

        $morning = [];
        $evening = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = $local->copy()->subDays($i)->toDateString();
            $morning[] = $this->readPeriodOn($user, $date, ReminderType::Morning);
            $evening[] = $this->readPeriodOn($user, $date, ReminderType::Evening);
        }

        $morningCount = count(array_filter($morning));
        $eveningCount = count(array_filter($evening));
        if ($morningCount === 0 && $eveningCount === 0) {
            $user->last_weekly_summary_at = now();
            $user->save();

            return false;
        }

        $closing = ($morningCount + $eveningCount) >= 10
            ? __('bot.weekly_summary_closing_strong')
            : __('bot.weekly_summary_closing_gentle');

        $text = __('bot.weekly_summary', [
            'morning_dots' => $this->dots($morning),
            'evening_dots' => $this->dots($evening),
            'morning_count' => $morningCount,
            'evening_count' => $eveningCount,
            'closing' => $closing,
        ]);

        if (! $this->deliver($user, $text)) {
            return false;
        }

        $user->last_weekly_summary_at = now();
        $user->save();
        $this->events->record($user, 'weekly_summary_sent');

        return true;
    }

    /**
     * @param  list<bool>  $days
     */
    private function dots(array $days): string
    {
        return implode('', array_map(fn (bool $done) => $done ? '🟢' : '⚪', $days));
    }

    private function readPeriodOn(User $user, string $localDate, ReminderType $type): bool
    {
        $completedLog = ReminderLog::query()
            ->where('user_id', $user->id)
            ->where('reminder_type', $type->value)
            ->whereDate('scheduled_local_date', $localDate)
            ->where('status', ReminderLogStatus::Completed->value)
            ->exists();
        if ($completedLog) {
            return true;
        }

        $period = $type === ReminderType::Evening ? 'evening' : 'morning';

        return ReadingSession::query()
            ->where('user_id', $user->id)
            ->whereNotNull('completed_at')
            ->where('started_at', '>=', now()->subDays(10))
            ->get(['collection_key', 'started_at'])
            ->contains(function (ReadingSession $session) use ($user, $localDate, $period) {
                if (ReadingSessionRecorder::group($session->collection_key) !== $period) {
                    return false;
                }
                try {
                    return $session->started_at->timezone($user->timezone)->toDateString() === $localDate;
                } catch (Throwable) {
                    return false;
                }
            });
    }

    private function sendTimeFitAsks(?float $deadline): int
    {
        $sent = 0;
        $users = User::query()
            ->where('onboarding_state', OnboardingState::Completed)
            ->where('reminders_enabled', true)
            ->whereNotNull('timezone')
            ->where(function ($query) {
                $query->whereNull('time_fit_asked_at')
                    ->orWhere('time_fit_asked_at', '<=', now()->subDays(self::TIME_FIT_COOLDOWN_DAYS));
            })
            ->where('last_interaction_at', '>=', now()->subDays(30))
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get();

        foreach ($users as $user) {
            if ($this->overBudget($deadline)) {
                break;
            }
            if (! $this->needsTimeFitAsk($user) || ! $this->isQuietAfternoon($user)) {
                continue;
            }

            $markup = [
                'inline_keyboard' => [
                    [['text' => __('bot.time_fit_ok_button'), 'callback_data' => 'time_fit:ok']],
                    [['text' => __('bot.time_fit_change_button'), 'callback_data' => 'time_fit:change']],
                ],
            ];
            if (! $this->deliver($user, __('bot.time_fit_ask'), $markup)) {
                continue;
            }
            $user->time_fit_asked_at = now();
            $user->save();
            $this->events->record($user, 'time_fit_asked');
            $sent++;
        }

        return $sent;
    }

    private function needsTimeFitAsk(User $user): bool
    {
        $logs = ReminderLog::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [ReminderLogStatus::Sent, ReminderLogStatus::Completed])
            ->whereNotNull('sent_at')
            ->orderByDesc('sent_at')
            ->limit(3)
            ->get(['id', 'sent_at', 'status', 'reading_started_at']);

        if ($logs->count() < 3) {
            return false;
        }

        foreach ($logs as $log) {
            if ($log->status === ReminderLogStatus::Completed || $log->reading_started_at !== null) {
                return false;
            }
            if (ReadingSession::query()->where('user_id', $user->id)->where('reminder_log_id', $log->id)->exists()) {
                return false;
            }
        }

        return true;
    }

    private function isQuietAfternoon(User $user): bool
    {
        try {
            $local = now($user->timezone);
        } catch (Throwable) {
            return false;
        }

        if ($local->hour < 11 || $local->hour >= 16) {
            return false;
        }

        foreach ([(string) $user->morning_time, (string) $user->evening_time] as $time) {
            if (preg_match('/^(\d{2}):(\d{2})/', $time, $m) !== 1) {
                continue;
            }
            $minutes = ((int) $m[1]) * 60 + (int) $m[2];
            $nowMinutes = $local->hour * 60 + $local->minute;
            if (abs($nowMinutes - $minutes) < 120) {
                return false;
            }
        }

        return true;
    }

    private function sendComebacks(?float $deadline): int
    {
        $sent = 0;
        $users = User::query()
            ->where('onboarding_state', OnboardingState::Completed)
            ->where('reminders_enabled', true)
            ->whereNotNull('timezone')
            ->whereNotNull('last_interaction_at')
            ->where('last_interaction_at', '<=', now()->subDays(self::COMEBACK_AFTER_DAYS))
            ->where(function ($query) {
                $query->whereNull('comeback_sent_at')
                    ->orWhere('comeback_sent_at', '<=', now()->subDays(self::COMEBACK_COOLDOWN_DAYS));
            })
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get();

        foreach ($users as $user) {
            if ($this->overBudget($deadline)) {
                break;
            }
            if (! $this->isQuietAfternoon($user)) {
                continue;
            }

            $text = __('bot.comeback', [
                'morning' => e($user->formattedMorningTime() ?: '—'),
                'evening' => e($user->formattedEveningTime() ?: '—'),
            ]);
            $markup = [
                'inline_keyboard' => [
                    [['text' => __('bot.comeback_continue_button'), 'callback_data' => 'comeback:continue']],
                    [['text' => __('bot.comeback_change_button'), 'callback_data' => 'comeback:change']],
                ],
            ];
            if (! $this->deliver($user, $text, $markup)) {
                continue;
            }
            $user->comeback_sent_at = now();
            $user->save();
            $this->events->record($user, 'comeback_sent');
            $sent++;
        }

        return $sent;
    }

    private function sendFeedbackNudges(?float $deadline): int
    {
        $sent = 0;
        $todayKey = 'engagement:feedback_nudges:'.now()->toDateString();
        $already = (int) Cache::get($todayKey, 0);
        if ($already >= self::FEEDBACK_DAILY_CAP) {
            return 0;
        }

        $users = User::query()
            ->where('onboarding_state', OnboardingState::Completed)
            ->where('reminders_enabled', true)
            ->whereNotNull('timezone')
            ->where('created_at', '<=', now()->subDays(self::FEEDBACK_MIN_DAYS))
            ->where('last_interaction_at', '>=', now()->subDays(self::FEEDBACK_MIN_DAYS))
            ->where(function ($query) {
                $query->whereNull('feedback_nudge_at')
                    ->orWhere('feedback_nudge_at', '<=', now()->subDays(self::FEEDBACK_IGNORE_DAYS));
            })
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get();

        foreach ($users as $user) {
            if ($this->overBudget($deadline) || $already + $sent >= self::FEEDBACK_DAILY_CAP) {
                break;
            }
            if (! $this->isQuietAfternoon($user)) {
                continue;
            }
            if (ReadingSession::query()->where('user_id', $user->id)->count() < self::FEEDBACK_MIN_SESSIONS) {
                continue;
            }
            $lastFeedback = Feedback::query()->where('user_id', $user->id)->latest('id')->value('created_at');
            if ($lastFeedback !== null && Carbon::parse($lastFeedback)->gt(now()->subDays(self::FEEDBACK_AFTER_SEND_DAYS))) {
                continue;
            }

            $markup = [
                'inline_keyboard' => [
                    [['text' => __('bot.feedback_button'), 'callback_data' => 'feedback:start', 'style' => 'success']],
                    [['text' => __('bot.feedback_later_button'), 'callback_data' => 'feedback:later']],
                ],
            ];
            if (! $this->deliver($user, __('bot.feedback_nudge'), $markup)) {
                continue;
            }
            $user->feedback_nudge_at = now();
            $user->save();
            $this->events->record($user, 'feedback_nudge_sent');
            $sent++;
        }

        if ($sent > 0) {
            Cache::put($todayKey, $already + $sent, now()->endOfDay());
        }

        return $sent;
    }

    /**
     * @param  array<string, mixed>  $markup
     */
    private function deliver(User $user, string $text, array $markup = []): bool
    {
        try {
            $this->telegram->sendMessage($user->chat_id, $text, $markup);

            return true;
        } catch (TelegramApiException $exception) {
            if ($exception->isBlockedByUser()) {
                $this->events->record($user, 'bot_blocked', ['from' => 'engagement']);
            }
            Log::channel('telegram')->warning('Engagement message failed', [
                'user_id' => $user->id,
                'error' => TelegramClient::redact($exception->getMessage()),
            ]);
        } catch (Throwable $exception) {
            Log::channel('telegram')->warning('Engagement message failed', [
                'user_id' => $user->id,
                'error' => TelegramClient::redact($exception->getMessage()),
            ]);
        }

        return false;
    }
}
