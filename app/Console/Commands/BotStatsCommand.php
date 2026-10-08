<?php

namespace App\Console\Commands;

use App\Models\BotEvent;
use App\Services\BotEventRecorder;
use Illuminate\Console\Command;

class BotStatsCommand extends Command
{
    protected $signature = 'bot:stats {--days=7 : How many days back to report}';

    protected $description = 'Report how people used the bot: setup, mistakes, unclear messages, reminders and readings.';

    private const QUICK_UNDO_SECONDS = 120;

    public function handle(BotEventRecorder $recorder): int
    {
        $recorder->pruneUnknownText();
        $days = max(1, (int) $this->option('days'));
        $since = now()->subDays($days);

        $counts = [];
        $meta = [];
        $quickUndo = 0;
        $users = [];

        BotEvent::query()
            ->where('created_at', '>=', $since)
            ->orderBy('id')
            ->lazyById(500)
            ->each(function (BotEvent $event) use (&$counts, &$meta, &$quickUndo, &$users) {
                $counts[$event->event] = ($counts[$event->event] ?? 0) + 1;
                if ($event->user_id !== null) {
                    $users[$event->user_id] = true;
                }

                $value = match ($event->event) {
                    'time_error' => $event->meta['reason'] ?? null,
                    'media' => $event->meta['kind'] ?? null,
                    'intent' => $event->meta['intent'] ?? null,
                    'help_topic' => $event->meta['topic'] ?? null,
                    'onboarding_step' => $event->meta['step'] ?? null,
                    'unknown_text' => $event->meta['text'] ?? null,
                    'reading_completed' => ($event->meta['scope'] ?? '?').' · '.($event->meta['collection'] ?? '?'),
                    default => null,
                };
                if (is_string($value) && $value !== '') {
                    $meta[$event->event][$value] = ($meta[$event->event][$value] ?? 0) + 1;
                }

                $after = $event->meta['seconds_after_pause'] ?? null;
                if ($event->event === 'resumed' && is_int($after) && $after <= self::QUICK_UNDO_SECONDS) {
                    $quickUndo++;
                }
            });

        $this->info("آخر {$days} أيام · ".count($users).' مستخدم تفاعل مع البوت');

        if ($counts === []) {
            $this->line('لا أحداث مسجّلة في هذه الفترة.');

            return self::SUCCESS;
        }

        arsort($counts);
        $this->table(['الحدث', 'العدد'], collect($counts)->map(fn ($n, $event) => [$event, $n])->values()->all());

        $this->newLine();
        $this->line('الإعداد: بدأ '.($counts['onboarding_started'] ?? 0).' · أكمله '.($counts['onboarding_completed'] ?? 0));
        $this->line('التذكير: فُتح '.($counts['reminder_opened'] ?? 0).' · قُرئ حتى آخره '.$this->sumPrefix($meta['reading_completed'] ?? [], 'reminder'));
        $this->line('الإيقاف: '.($counts['paused'] ?? 0).' · رجوع سريع خلال دقيقتين: '.$quickUndo);

        foreach ([
            'onboarding_step' => 'خطوات الإعداد',
            'time_error' => 'أخطاء كتابة الوقت',
            'media' => 'رسائل غير نصية',
            'intent' => 'كلمات فُهمت',
            'help_topic' => 'أسئلة المساعدة',
            'reading_completed' => 'قراءات اكتملت',
            'unknown_text' => 'نصوص لم تُفهم (أكثر 15)',
        ] as $event => $title) {
            if (empty($meta[$event])) {
                continue;
            }
            arsort($meta[$event]);
            $this->newLine();
            $this->line("<comment>{$title}</comment>");
            $this->table(['القيمة', 'العدد'], collect($meta[$event])->take(15)->map(fn ($n, $value) => [$value, $n])->values()->all());
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $values
     */
    private function sumPrefix(array $values, string $prefix): int
    {
        return (int) collect($values)->filter(fn ($n, $key) => str_starts_with((string) $key, $prefix.' '))->sum();
    }
}
