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
            $this->row('الإجمالي', $total),
            $this->row('جدد في هذه الفترة', $new),
            $this->row('نشطون آخر 24 ساعة', $activeDay),
            $this->row('نشطون خلال الفترة', $activeWindow),
            $this->row('أكملوا الإعداد (منذ البداية)', $onboarded),
        ];
        if ($started > 0) {
            $lines[] = $this->row('أكملوا الإعداد في هذه الفترة', "{$finished} من {$started}", $this->pct($finished, $started));
        }
        if ($cohortSize > 0) {
            $lines[] = $this->row("عادوا من جدد الأيام الـ{$days} السابقة", "{$returned} من {$cohortSize}", $this->pct($returned, $cohortSize));
        }
        if ($blocked > 0) {
            $lines[] = $this->row('حظروا البوت', $blocked);
        }

        return implode("\n", $lines);
    }

    private function remindersSection(Carbon $since): string
    {
        $onboarded = User::query()->where('onboarding_state', OnboardingState::Completed);
        $onboardedCount = (clone $onboarded)->count();
        $direct = (clone $onboarded)->where('reminder_style', 'direct')->count();
        $followUpOn = (clone $onboarded)->where('follow_up_enabled', true)->count();
        $paused = $this->eventCount('paused', $since);

        $settings = [
            $this->row('مفعّلة لدى', User::query()->where('reminders_enabled', true)->count()),
            $this->row('شكل التذكير', "مباشر {$direct} · تنبيه ".($onboardedCount - $direct)),
            $this->row('التذكير الثاني مفعّل لدى', $followUpOn),
        ];
        if ($paused > 0) {
            $settings[] = $this->row('مرات إيقاف التذكيرات', $paused);
        }

        $logs = ReminderLog::query()
            ->where('sent_at', '>=', $since)
            ->whereIn('status', [ReminderLogStatus::Sent, ReminderLogStatus::Completed])
            ->get(['id', 'reminder_type', 'sent_at', 'status', 'follow_up_sent_at']);
        if ($logs->isEmpty()) {
            return implode("\n", ['🔔 <b>التذكيرات</b>', ...$settings, 'لم يُرسَل أي تذكير في هذه الفترة.']);
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
            ...$settings,
            '',
            '<i>في هذه الفترة</i>',
            $this->row('أُرسل', $logs->count()),
            $this->row('فُتح', $opened->count(), $this->pct($opened->count(), $logs->count())),
            $this->row('ضغطوا «تم»', $done, $this->pct($done, $logs->count())),
        ];
        foreach ([ReminderType::Morning->value => '🌅 الصباح', ReminderType::Evening->value => '🌙 المساء'] as $type => $label) {
            $ofType = $logs->filter(fn (ReminderLog $log) => $log->reminder_type?->value === $type);
            if ($ofType->isNotEmpty()) {
                $openedOfType = $ofType->filter(fn (ReminderLog $log) => $openedAt->has($log->id))->count();
                $lines[] = $this->row($label, "فُتح {$openedOfType} من {$ofType->count()}", $this->pct($openedOfType, $ofType->count()));
            }
        }
        if ($minutes->isNotEmpty()) {
            $lines[] = $this->row('يُفتح عادةً بعد', $minutes[intdiv($minutes->count(), 2)].' دقيقة');
        }

        $followUps = $logs->whereNotNull('follow_up_sent_at');
        if ($followUps->isNotEmpty()) {
            $afterFollowUp = $followUps->filter(fn (ReminderLog $log) => $openedAt->has($log->id) && $openedAt[$log->id]->gte($log->follow_up_sent_at))->count();
            $lines[] = $this->row('التذكير الثاني', "فُتح بعده {$afterFollowUp} من {$followUps->count()}", $this->pct($afterFollowUp, $followUps->count()));
        }

        return implode("\n", $lines);
    }

    /**
     * @param  Collection<int, ReadingSession>  $sessions
     */
    private function readingSection(Collection $sessions): string
    {
        if ($sessions->isEmpty()) {
            return "📖 <b>القراءة</b>\nلا قراءات في هذه الفترة.";
        }

        $fresh = $sessions->where('source', '!=', 'continue');
        $completed = $fresh->whereNotNull('completed_at')->count();
        $lines = [
            '📖 <b>القراءة</b>',
            $this->row('مرات فتح الورد', $fresh->count()),
            $this->row('وصلوا إلى آخره', $completed, $this->pct($completed, $fresh->count())),
        ];
        $continued = $sessions->where('source', 'continue')->count();
        if ($continued > 0) {
            $lines[] = $this->row('أكملوا الكامل بعد المختصر', $continued);
        }

        $lines[] = '';
        $lines[] = '<i>حسب الورد</i>';
        foreach ($fresh->groupBy(fn (ReadingSession $s) => ReadingSessionRecorder::group($s->collection_key))->sortByDesc(fn (Collection $g) => $g->count()) as $group => $ofGroup) {
            $line = $this->item($this->content->collectionHeading((string) $group), $ofGroup->count())
                .' · أكملوه '.$this->percent($ofGroup->whereNotNull('completed_at')->count(), $ofGroup->count());
            $full = $ofGroup->filter(fn (ReadingSession $s) => str_ends_with($s->collection_key, '_full'))->count();
            if ($full > 0 || $ofGroup->contains(fn (ReadingSession $s) => str_ends_with($s->collection_key, '_short'))) {
                $line .= ' · الكامل '.$this->percent($full, $ofGroup->count());
            }
            $lines[] = $line;
        }

        $lines[] = '';
        $lines[] = '<i>من أين يفتحون الورد</i>';
        foreach (['reminder' => 'من التذكير', 'direct' => 'تذكير مباشر', 'manual' => 'من الأزرار'] as $source => $label) {
            $lines[] = $this->item($label, $this->percent($fresh->where('source', $source)->count(), $fresh->count()));
        }

        $hours = $fresh->whereNotNull('local_hour')->countBy('local_hour')->sortDesc()->take(3);
        if ($hours->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '<i>أكثر ساعات القراءة (على توقيتهم)</i>';
            foreach ($hours as $hour => $n) {
                $lines[] = $this->item(ReminderScheduleCalculator::format12Hour(sprintf('%02d:00', $hour)), $n);
            }
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
            $lines[] = $this->item($this->collectionLabel($first), $group->count()).' لم يُكملوه';
            $lines[] = "      أكثر توقف عند الذكر {$card} من {$first->total} ({$stops->first()} منهم)";
            $lines[] = "      وصلوا في المتوسط إلى {$average}%";
        }

        return implode("\n", $lines);
    }

    private function usageSection(Carbon $since): string
    {
        $feedback = Feedback::query()->where('created_at', '>=', $since)->count();
        $unanswered = Feedback::query()->whereNull('replied_at')->count();
        $groups = GroupChat::query()->where('bot_member', true);
        $lines = [
            '🌟 <b>استخدامات أخرى</b>',
            $this->row('💬 ملاحظات جديدة', $feedback),
            $this->row('💬 ملاحظات بلا رد (الكل)', $unanswered),
            $this->row('👥 المجموعات المفعّلة', (clone $groups)->where('enabled', true)->count().' من '.(clone $groups)->count()),
        ];

        $saved = BotEvent::query()->where('event', 'wird_saved')->where('created_at', '>=', $since)->get(['meta']);
        if ($saved->isNotEmpty()) {
            $lines[] = $this->row('📥 مرات طلب الورد كاملًا', $saved->count())
                .' (صور '.$saved->where('meta.format', 'images')->count().' · PDF '.$saved->where('meta.format', 'pdf')->count().')';
        }

        $unclear = $this->eventCount(BotEventRecorder::UNKNOWN_TEXT, $since);
        if ($unclear > 0) {
            $lines[] = $this->row('❔ رسائل لم يفهمها البوت', $unclear).' (التفاصيل في bot:stats)';
        }

        $additional = $this->topMeta('additional_item', 'item', $since, 3);
        if ($additional !== []) {
            $lines[] = '';
            $lines[] = '<i>أكثر الأذكار الإضافية فتحًا</i>';
            array_push($lines, ...$additional);
        }

        $topics = __('bot.help_topics');
        $help = $this->topMeta('help_topic', 'topic', $since, 3, fn (string $key) => $topics[$key]['label'] ?? $key);
        if ($help !== []) {
            $lines[] = '';
            $lines[] = '<i>أكثر أسئلة المساعدة</i>';
            array_push($lines, ...$help);
        }

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
            ->map(fn (int $n, string $value) => $this->item(e($label !== null ? $label($value) : $value), $n))
            ->values()
            ->all();
    }

    private function row(string $label, int|string $value, string $suffix = ''): string
    {
        return rtrim("{$label}: <b>{$value}</b> {$suffix}");
    }

    private function item(string $label, int|string $value): string
    {
        return "▫️ {$label} — <b>{$value}</b>";
    }

    private function percent(int $part, int $whole): string
    {
        return $whole > 0 ? (int) round($part * 100 / $whole).'%' : '—';
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
