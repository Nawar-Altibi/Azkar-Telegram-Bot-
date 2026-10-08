<?php

namespace App\Services;

use App\Enums\OnboardingState;
use App\Enums\ReminderLogStatus;
use App\Enums\ReminderType;
use App\Models\BotEvent;
use App\Models\Feedback;
use App\Models\GroupChat;
use App\Models\ReadingSession;
use App\Models\ReminderLog;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The owner's usage report: sent on /stats from the admin chat and every Friday morning.
 */
class AdminReportService
{
    public const LAST_SLOT_KEY = 'admin_report:last_slot';

    private const WEEKLY_HOUR = 10;

    // Sessions idle for less than this may still be in progress, so they are not counted as drop-offs yet.
    private const DROP_OFF_IDLE_MINUTES = 60;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly AdhkarContentService $content,
    ) {}

    public function build(int $days): string
    {
        $since = now()->subDays($days);
        $sessions = ReadingSession::query()
            ->where('started_at', '>=', $since)
            ->get(['collection_key', 'phase', 'source', 'total', 'furthest', 'local_hour', 'last_seen_at', 'completed_at']);
        $sections = [
            "📊 <b>تقرير آخر {$days} أيام</b>\n<i>{$since->toDateString()} ← ".now()->toDateString().'</i>',
            $this->usersSection($since, $days),
            $this->remindersSection($since),
            $this->readingSection($sessions),
            $this->dropOffSection($sessions),
            $this->usageSection($since),
        ];

        return Str::limit(implode("\n\n", array_filter($sections)), 4000);
    }

    public function isDue(): bool
    {
        $next = $this->nextDueAt();

        return $next !== null && $next->lte(now());
    }

    /**
     * Null when no admin chat is configured; otherwise this week's Friday slot until it is sent, then next week's.
     */
    public function nextDueAt(): ?Carbon
    {
        if (config('telegram.admin_chat_id') === null) {
            return null;
        }

        $slot = $this->lastSlot();

        return Cache::get(self::LAST_SLOT_KEY) === $slot->toIso8601String()
            ? $slot->copy()->addWeek()->utc()
            : $slot->copy()->utc();
    }

    public function sendWeeklyIfDue(bool $force = false): bool
    {
        $admin = config('telegram.admin_chat_id');
        if ($admin === null || (! $force && ! $this->isDue())) {
            return false;
        }

        // Marked before sending: a chat that cannot be reached must not be retried on every tick.
        Cache::forever(self::LAST_SLOT_KEY, $this->lastSlot()->toIso8601String());
        try {
            $this->telegram->sendMessage($admin, $this->build(7));
        } catch (Throwable $exception) {
            Log::channel('telegram')->warning('Weekly report not delivered', [
                'error' => TelegramClient::redact($exception->getMessage()),
            ]);

            return false;
        }

        return true;
    }

    private function lastSlot(): Carbon
    {
        $now = now((string) config('telegram.admin_timezone', 'Asia/Damascus'));
        $slot = $now->copy()->setTime(self::WEEKLY_HOUR, 0);
        while ($slot->dayOfWeek !== Carbon::FRIDAY || $slot->gt($now)) {
            $slot->subDay();
        }

        return $slot;
    }

    private function usersSection(Carbon $since, int $days): string
    {
        $total = User::query()->count();
        $new = User::query()->where('created_at', '>=', $since)->count();
        $onboarded = User::query()->where('onboarding_state', OnboardingState::Completed)->count();
        $started = $this->eventCount('onboarding_started', $since);
        $finished = $this->eventCount('onboarding_completed', $since);
        $activeDay = User::query()->where('last_interaction_at', '>=', now()->subDay())->count();
        $activeWindow = User::query()->where('last_interaction_at', '>=', $since)->count();
        $remindersOn = User::query()->where('reminders_enabled', true)->count();

        $blocked = BotEvent::query()->where('event', 'bot_blocked')->where('created_at', '>=', $since)->pluck('user_id')
            ->merge(ReminderLog::query()->where('error_message', 'blocked_by_user')->where('updated_at', '>=', $since)->pluck('user_id'))
            ->filter()
            ->unique()
            ->count();

        $cohort = User::query()->whereBetween('created_at', [$since->copy()->subDays($days), $since]);
        $cohortSize = (clone $cohort)->count();
        $returned = (clone $cohort)->where('last_interaction_at', '>=', $since)->count();

        $lines = [
            '👥 <b>المستخدمون</b>',
            "• الإجمالي: {$total} · الجدد: {$new} · أكملوا الإعداد (منذ البداية): {$onboarded}",
            "• الإعداد في هذه الفترة: بدأه {$started} · أكمله {$finished} ".$this->pct($finished, $started),
            "• النشطون: آخر 24 ساعة {$activeDay} · خلال الفترة {$activeWindow}",
            "• التذكيرات مفعّلة لدى {$remindersOn} · أُوقفت {$this->eventCount('paused', $since)} مرة · حظروا البوت {$blocked}",
        ];
        if ($cohortSize > 0) {
            $lines[] = "• من انضموا في الأيام الـ{$days} السابقة ({$cohortSize}): عادوا في هذه الفترة ".$this->pct($returned, $cohortSize);
        }

        return implode("\n", $lines);
    }

    private function remindersSection(Carbon $since): string
    {
        $logs = ReminderLog::query()
            ->where('sent_at', '>=', $since)
            ->whereIn('status', [ReminderLogStatus::Sent, ReminderLogStatus::Completed])
            ->get(['id', 'reminder_type', 'sent_at', 'status', 'follow_up_sent_at']);
        if ($logs->isEmpty()) {
            return "🔔 <b>التذكيرات</b>\n• لم يُرسَل أي تذكير في هذه الفترة.";
        }

        $openedAt = ReadingSession::query()
            ->whereNotNull('reminder_log_id')
            ->where('started_at', '>=', $since)
            ->orderBy('started_at')
            ->get(['reminder_log_id', 'started_at'])
            ->unique('reminder_log_id')
            ->mapWithKeys(fn (ReadingSession $session) => [$session->reminder_log_id => $session->started_at]);

        $opened = $logs->filter(fn (ReminderLog $log) => $openedAt->has($log->id));
        $done = $logs->where('status', ReminderLogStatus::Completed)->count();
        $minutes = $opened
            ->map(fn (ReminderLog $log) => max(0, (int) round($log->sent_at->diffInMinutes($openedAt[$log->id], true))))
            ->sort()
            ->values();

        $lines = [
            '🔔 <b>التذكيرات</b>',
            "• أُرسل {$logs->count()} · فُتح {$opened->count()} ".$this->pct($opened->count(), $logs->count())
                ." · ضغطوا «تم» {$done} ".$this->pct($done, $logs->count()),
        ];
        foreach ([ReminderType::Morning->value => '🌅 الصباح', ReminderType::Evening->value => '🌙 المساء'] as $type => $label) {
            $ofType = $logs->filter(fn (ReminderLog $log) => $log->reminder_type?->value === $type);
            if ($ofType->isNotEmpty()) {
                $openedOfType = $ofType->filter(fn (ReminderLog $log) => $openedAt->has($log->id))->count();
                $lines[] = "• {$label}: فُتح {$openedOfType} من {$ofType->count()} ".$this->pct($openedOfType, $ofType->count());
            }
        }
        if ($minutes->isNotEmpty()) {
            $lines[] = '• يُفتح عادةً بعد '.$minutes[intdiv($minutes->count(), 2)].' دقيقة من وصوله';
        }

        $followUps = $logs->whereNotNull('follow_up_sent_at');
        if ($followUps->isNotEmpty()) {
            $afterFollowUp = $followUps->filter(fn (ReminderLog $log) => $openedAt->has($log->id) && $openedAt[$log->id]->gte($log->follow_up_sent_at))->count();
            $lines[] = "• التذكير الثاني: أُرسل {$followUps->count()} · فُتح بعده {$afterFollowUp} ".$this->pct($afterFollowUp, $followUps->count());
        }

        return implode("\n", $lines);
    }

    /**
     * @param  Collection<int, ReadingSession>  $sessions
     */
    private function readingSection(Collection $sessions): string
    {
        if ($sessions->isEmpty()) {
            return "📖 <b>القراءة</b>\n• لا قراءات في هذه الفترة.";
        }

        $fresh = $sessions->where('source', '!=', 'continue');
        $completed = $fresh->whereNotNull('completed_at')->count();
        $lines = [
            '📖 <b>القراءة</b>',
            "• فُتح ورد {$fresh->count()} مرة · وصلوا إلى آخره {$completed} ".$this->pct($completed, $fresh->count()),
        ];

        foreach ($fresh->groupBy(fn (ReadingSession $s) => ReadingSessionRecorder::group($s->collection_key))->sortByDesc(fn (Collection $g) => $g->count()) as $group => $ofGroup) {
            $line = '• '.$this->content->collectionHeading((string) $group).": {$ofGroup->count()} · أكملوه ".$this->pct($ofGroup->whereNotNull('completed_at')->count(), $ofGroup->count());
            $full = $ofGroup->filter(fn (ReadingSession $s) => str_ends_with($s->collection_key, '_full'))->count();
            if ($full > 0 || $ofGroup->contains(fn (ReadingSession $s) => str_ends_with($s->collection_key, '_short'))) {
                $line .= ' · كامل '.$this->pct($full, $ofGroup->count());
            }
            $lines[] = $line;
        }

        $continued = $sessions->where('source', 'continue')->count();
        if ($continued > 0) {
            $lines[] = "• أكملوا الورد الكامل بعد المختصر: {$continued}";
        }

        $sources = ['reminder' => 'من التذكير', 'direct' => 'تذكير مباشر', 'manual' => 'من الأزرار'];
        $lines[] = '• مصدر الفتح: '.collect($sources)
            ->map(fn (string $label, string $source) => $label.' '.$this->pct($fresh->where('source', $source)->count(), $fresh->count()))
            ->implode(' · ');

        $hours = $fresh->whereNotNull('local_hour')->countBy('local_hour')->sortDesc()->take(3);
        if ($hours->isNotEmpty()) {
            $lines[] = '• أكثر ساعات القراءة (على توقيتهم): '.$hours
                ->map(fn (int $n, int $hour) => ReminderScheduleCalculator::format12Hour(sprintf('%02d:00', $hour))." ({$n})")
                ->implode(' · ');
        }

        return implode("\n", $lines);
    }

    /**
     * @param  Collection<int, ReadingSession>  $sessions
     */
    private function dropOffSection(Collection $sessions): string
    {
        $stopped = $sessions
            ->filter(fn (ReadingSession $s) => $s->completed_at === null
                && $s->total > 1
                && $s->last_seen_at->lt(now()->subMinutes(self::DROP_OFF_IDLE_MINUTES)))
            ->groupBy(fn (ReadingSession $s) => $s->collection_key.'|'.$s->phase)
            ->sortByDesc(fn (Collection $group) => $group->count())
            ->take(4);
        if ($stopped->isEmpty()) {
            return '';
        }

        $lines = ['🚪 <b>أين يتوقفون؟</b>'];
        foreach ($stopped as $group) {
            /** @var ReadingSession $first */
            $first = $group->first();
            $stops = $group->countBy('furthest')->sortDesc();
            $card = (int) $stops->keys()->first();
            $average = (int) round($group->avg(fn (ReadingSession $s) => $s->furthest / max(1, $s->total)) * 100);
            $lines[] = '• '.$this->collectionLabel($first)
                .": {$group->count()} لم يُكملوا · أكثر توقف عند الذكر {$card} من {$first->total} ({$stops->first()} منهم) · وصلوا في المتوسط إلى {$average}%";
        }

        return implode("\n", $lines);
    }

    private function usageSection(Carbon $since): string
    {
        $lines = ['🌟 <b>استخدامات أخرى</b>'];

        $additional = $this->topMeta('additional_item', 'item', $since, 3);
        if ($additional !== []) {
            $lines[] = '• أكثر الأذكار الإضافية: '.implode(' · ', $additional);
        }

        $saved = BotEvent::query()->where('event', 'wird_saved')->where('created_at', '>=', $since)->get(['meta']);
        if ($saved->isNotEmpty()) {
            $lines[] = "• طُلب الورد كاملًا {$saved->count()} مرة (صور ".$saved->where('meta.format', 'images')->count()
                .' · PDF '.$saved->where('meta.format', 'pdf')->count().')';
        }

        $topics = __('bot.help_topics');
        $help = $this->topMeta('help_topic', 'topic', $since, 3, fn (string $key) => $topics[$key]['label'] ?? $key);
        if ($help !== []) {
            $lines[] = '• أكثر أسئلة المساعدة: '.implode(' · ', $help);
        }

        $onboarded = User::query()->where('onboarding_state', OnboardingState::Completed);
        $direct = (clone $onboarded)->where('reminder_style', 'direct')->count();
        $followUp = (clone $onboarded)->where('follow_up_enabled', true)->count();
        $lines[] = "• شكل التذكير: مباشر {$direct} · تنبيه ".((clone $onboarded)->count() - $direct)." · التذكير الثاني مفعّل لدى {$followUp}";

        $unclear = $this->eventCount(BotEventRecorder::UNKNOWN_TEXT, $since);
        if ($unclear > 0) {
            $lines[] = "• رسائل لم يفهمها البوت: {$unclear} (التفاصيل في bot:stats)";
        }

        $feedback = Feedback::query()->where('created_at', '>=', $since)->count();
        $unanswered = Feedback::query()->whereNull('replied_at')->count();
        $lines[] = "• 💬 ملاحظات جديدة: {$feedback} · دون رد (الإجمالي): {$unanswered}";

        $groups = GroupChat::query()->where('bot_member', true);
        $lines[] = '• 👥 المجموعات: '.(clone $groups)->where('enabled', true)->count().' مفعّلة من '.(clone $groups)->count();

        return implode("\n", $lines);
    }

    private function collectionLabel(ReadingSession $session): string
    {
        $heading = $this->content->collectionHeading($session->collection_key);

        return match (true) {
            $session->phase === 'f' => $heading.' (تكملة الكامل)',
            str_ends_with($session->collection_key, '_full') => $heading.' (كامل)',
            str_ends_with($session->collection_key, '_short') => $heading.' (مختصر)',
            default => $heading,
        };
    }

    /**
     * @return list<string>
     */
    private function topMeta(string $event, string $field, Carbon $since, int $limit, ?callable $label = null): array
    {
        return BotEvent::query()
            ->where('event', $event)
            ->where('created_at', '>=', $since)
            ->get(['meta'])
            ->map(fn (BotEvent $e) => $e->meta[$field] ?? null)
            ->filter(fn ($value) => is_string($value) && $value !== '')
            ->countBy()
            ->sortDesc()
            ->take($limit)
            ->map(fn (int $n, string $value) => e($label !== null ? $label($value) : $value)." ({$n})")
            ->values()
            ->all();
    }

    private function eventCount(string $event, Carbon $since): int
    {
        return BotEvent::query()->where('event', $event)->where('created_at', '>=', $since)->count();
    }

    private function pct(int $part, int $whole): string
    {
        return $whole > 0 ? '('.(int) round($part * 100 / $whole).'%)' : '';
    }
}
