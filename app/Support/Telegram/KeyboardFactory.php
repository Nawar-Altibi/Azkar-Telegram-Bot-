<?php

namespace App\Support\Telegram;

use App\Models\User;
use App\Services\ReminderScheduleCalculator;

class KeyboardFactory
{
    /**
     * @return array<string, mixed>
     */
    public function tutorialStart(): array
    {
        return [
            'inline_keyboard' => [
                [['text' => __('bot.help_start_setup'), 'callback_data' => 'onb:begin']],
                [['text' => __('bot.help_topics.idea.label'), 'callback_data' => 'help:topic:idea']],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function helpMenu(bool $onboarded): array
    {
        $rows = [];
        foreach (__('bot.help_topics') as $key => $topic) {
            $rows[] = [['text' => $topic['label'], 'callback_data' => 'help:topic:'.$key]];
        }
        $rows[] = [['text' => __('bot.feedback_button'), 'callback_data' => 'feedback:start', 'style' => 'success']];
        $rows[] = $onboarded
            ? [['text' => __('bot.help_back'), 'callback_data' => 'nav:home']]
            : [['text' => __('bot.help_start_setup'), 'callback_data' => 'onb:begin', 'style' => 'primary']];

        return ['inline_keyboard' => $rows];
    }

    /**
     * @return array<string, mixed>
     */
    public function feedbackPrompt(): array
    {
        return ['inline_keyboard' => [[['text' => __('bot.feedback_cancel_button'), 'callback_data' => 'feedback:cancel']]]];
    }

    /**
     * @return array<string, mixed>
     */
    public function helpTopic(string $key, bool $onboarded): array
    {
        $rows = [];
        if (! $onboarded) {
            $rows[] = [['text' => __('bot.help_start_setup'), 'callback_data' => 'onb:begin']];
        } else {
            $action = match ($key) {
                'read' => ['text' => '📖 أذكار اليوم', 'callback_data' => 'today:menu'],
                'style' => ['text' => '🔔 اختر شكل التذكير', 'callback_data' => 'settings:style'],
                'time', 'stop', 'missing' => ['text' => '⚙️ افتح الإعدادات', 'callback_data' => 'settings:open'],
                default => null,
            };
            if ($action !== null) {
                $rows[] = [$action];
            }
        }
        $rows[] = [['text' => __('bot.help_more'), 'callback_data' => 'help:menu']];

        return ['inline_keyboard' => $rows];
    }

    /**
     * @return array<string, mixed>
     */
    public function timezoneOptions(bool $includeBack = true): array
    {
        $rows = [];
        $featured = config('adhkar.featured_timezones', []);
        $cities = array_filter(
            config('adhkar.timezones', []),
            fn (array $city) => in_array($city['label'], $featured, true),
        );
        $row = [];

        foreach ($cities as $index => $city) {
            $row[] = [
                'text' => $city['label'],
                'callback_data' => 'onb:tz:'.$index,
            ];
            if (count($row) === 2) {
                $rows[] = $this->rtl($row);
                $row = [];
            }
        }
        if ($row !== []) {
            $rows[] = $this->rtl($row);
        }
        // style colours the button on clients since Bot API 9.4; older clients ignore it.
        $rows[] = [['text' => '✍️ دولة أخرى (اكتب اسمها)', 'callback_data' => 'onb:tz:custom', 'style' => 'primary']];
        $rows[] = [['text' => '📍 حدد بلدي من موقعي', 'callback_data' => 'onb:loc', 'style' => 'success']];
        if ($includeBack) {
            $rows[] = $this->backOnlyRow();
        }

        return ['inline_keyboard' => $rows];
    }

    /**
     * Telegram can only ask for a location from a bottom keyboard button.
     *
     * @return array<string, mixed>
     */
    public function locationRequest(): array
    {
        return [
            'keyboard' => [
                [['text' => '📍 أرسل موقعي', 'request_location' => true]],
                [['text' => '🌍 سأختار من القائمة']],
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function locationConfirm(): array
    {
        return [
            'inline_keyboard' => [
                $this->rtl([
                    ['text' => '✅ نعم، صحيح', 'callback_data' => 'loc:yes'],
                    ['text' => '🌍 سأختار بنفسي', 'callback_data' => 'loc:no'],
                ]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function regionOptions(int $countryIndex): array
    {
        $regions = config('adhkar.timezones')[$countryIndex]['regions'] ?? [];
        $rows = [];
        foreach ($regions as $index => $region) {
            $rows[] = [[
                'text' => $region['label'],
                'callback_data' => "onb:tzr:{$countryIndex}:{$index}",
            ]];
        }
        $rows[] = $this->backOnlyRow();

        return ['inline_keyboard' => $rows];
    }

    /**
     * @return array<string, mixed>
     */
    public function timePresets(string $kind): array
    {
        $presets = $kind === 'evening'
            ? config('adhkar.evening_presets', [])
            : config('adhkar.morning_presets', []);

        $rows = [];
        $row = [];
        foreach ($presets as $time) {
            $encoded = str_replace(':', '', $time);
            $row[] = [
                'text' => ReminderScheduleCalculator::format12Hour($time),
                'callback_data' => "onb:time:{$kind}:{$encoded}",
            ];
            if (count($row) === 2) {
                $rows[] = $this->rtl($row);
                $row = [];
            }
        }
        if ($row !== []) {
            $rows[] = $this->rtl($row);
        }
        $rows[] = [['text' => '⏰ وقت آخر (اكتبه بنفسك)', 'callback_data' => "onb:time:{$kind}:custom"]];
        $rows[] = $this->backOnlyRow();

        return ['inline_keyboard' => $rows];
    }

    /**
     * @param  array<int, array{label:string,hm:string}>  $options
     * @return array<string, mixed>
     */
    public function timePeriodChoice(string $kind, array $options, bool $fromSettings): array
    {
        $row = [];
        foreach ($options as $option) {
            $hour = (int) substr($option['hm'], 0, 2);
            $row[] = [
                'text' => ($hour >= 4 && $hour < 18 ? '☀️ ' : '🌙 ').$option['label'],
                'callback_data' => "onb:time:{$kind}:{$option['hm']}",
            ];
        }

        return [
            'inline_keyboard' => [
                $this->rtl($row),
                $fromSettings ? $this->backCancelRow() : $this->backOnlyRow(),
            ],
        ];
    }

    /**
     * @param  array{prev?:string,next?:string,switch_to_full?:string,switch_to_short?:string,continue?:string,done?:string,save?:string}  $actions
     * @return array<string, mixed>
     */
    public function readingNav(array $actions): array
    {
        $rows = [];
        $steps = $this->rtl(array_values(array_filter([
            isset($actions['prev']) ? ['text' => 'السابق ➡️', 'callback_data' => $actions['prev']] : null,
            isset($actions['next']) ? ['text' => '⬅️ التالي', 'callback_data' => $actions['next']] : null,
        ])));
        if ($steps !== []) {
            $rows[] = $steps;
        }
        if (isset($actions['switch_to_full'])) {
            $rows[] = [['text' => '📖 بدّل إلى الورد الكامل', 'callback_data' => $actions['switch_to_full']]];
        }
        if (isset($actions['switch_to_short'])) {
            $rows[] = [['text' => '🌿 بدّل إلى الورد المختصر', 'callback_data' => $actions['switch_to_short']]];
        }
        if (isset($actions['save'])) {
            $rows[] = [['text' => '📥 أرسل الورد كاملًا', 'callback_data' => $actions['save']]];
        }
        if (isset($actions['continue'])) {
            $rows[] = [['text' => 'أكمل الورد الكامل', 'callback_data' => $actions['continue']]];
        }
        if (isset($actions['done'])) {
            $rows[] = [['text' => '✅ تم', 'callback_data' => $actions['done']]];
        }

        return ['inline_keyboard' => $rows];
    }

    /**
     * @return array<string, mixed>
     */
    public function printPdf(string $callback): array
    {
        return ['inline_keyboard' => [[['text' => __('bot.pdf_print_button'), 'callback_data' => $callback]]]];
    }

    /**
     * @return array<string, mixed>
     */
    public function settingsMenu(User $user): array
    {
        $reminderButton = $user->reminders_enabled
            ? ['text' => '⏸ إيقاف التذكيرات', 'callback_data' => 'settings:pause_ask']
            : ['text' => '▶️ تشغيل التذكيرات', 'callback_data' => 'settings:resume'];
        $styleButton = [
            'text' => '🔔 شكل التذكير: '.__($user->sendsReadingWithReminder() ? 'bot.style_direct' : 'bot.style_nudge'),
            'callback_data' => 'settings:style',
        ];
        $followUpButton = $user->follow_up_enabled
            ? ['text' => '🔁 تذكير ثانٍ إن نسيت: مفعّل ✅', 'callback_data' => 'settings:follow_up']
            : ['text' => '🔁 تذكير ثانٍ إن نسيت: معطّل', 'callback_data' => 'settings:follow_up'];

        return [
            'inline_keyboard' => [
                [['text' => '🌍 المنطقة', 'callback_data' => 'settings:timezone']],
                $this->rtl([
                    ['text' => '🌅 وقت الصباح', 'callback_data' => 'settings:morning_time'],
                    ['text' => '🌙 وقت المساء', 'callback_data' => 'settings:evening_time'],
                ]),
                [$styleButton],
                [$followUpButton],
                [$reminderButton],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function reminderStyle(User $user): array
    {
        $direct = $user->sendsReadingWithReminder();
        $mark = fn (bool $current) => $current ? '✅ ' : '';

        return [
            'inline_keyboard' => [
                [['text' => $mark(! $direct).'📩 '.__('bot.style_nudge'), 'callback_data' => 'settings:style:nudge']],
                [['text' => $mark($direct).'📖 '.__('bot.style_direct'), 'callback_data' => 'settings:style:direct']],
                [['text' => '🔙 رجوع', 'callback_data' => 'settings:open']],
            ],
        ];
    }

    /**
     * @param  string|null  $period  morning, evening or night from DayPeriod; that choice goes first and is colored
     */
    public function todayMenu(?string $period = null): array
    {
        $morning = ['text' => '🌅 أذكار الصباح', 'callback_data' => 'today:morning'];
        $evening = ['text' => '🌙 أذكار المساء', 'callback_data' => 'today:evening'];
        $bedtime = ['text' => '🛌 أذكار النوم', 'callback_data' => 'today:bedtime'];
        $current = function (array $button): array {
            $button['style'] = 'primary';

            return $button;
        };

        $choices = match ($period) {
            'morning' => [$current($morning), $evening],
            'evening' => [$current($evening), $morning],
            'night' => [$current($bedtime), $evening, $morning],
            default => [$morning, $evening],
        };

        return ['inline_keyboard' => array_map(fn (array $button) => [$button], $choices)];
    }

    /**
     * @param  string  $period  m or e
     * @return array<string, mixed>
     */
    public function modeChoice(string $period): array
    {
        return [
            'inline_keyboard' => [
                $this->rtl([
                    ['text' => '🌿 مختصر', 'callback_data' => "md:{$period}:s"],
                    ['text' => '📖 كامل', 'callback_data' => "md:{$period}:a"],
                ]),
                [['text' => '🔙 رجوع', 'callback_data' => 'today:menu']],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function quickSetup(): array
    {
        return [
            'inline_keyboard' => [
                [['text' => '✅ نعم، هذا مناسب', 'callback_data' => 'onb:quick:yes']],
                [['text' => '⏰ أريد أوقاتًا أخرى', 'callback_data' => 'onb:quick:custom']],
                $this->backOnlyRow(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function timezoneRetry(bool $fromSettings): array
    {
        $rows = [[['text' => __('bot.timezone_pick_from_list'), 'callback_data' => 'nav:back']]];
        if ($fromSettings) {
            $rows[] = [['text' => '❌ إلغاء', 'callback_data' => 'nav:cancel']];
        }

        return ['inline_keyboard' => $rows];
    }

    /**
     * @param  iterable<int, array{id:int,title:string}>  $items
     * @return array<string, mixed>
     */
    public function additionalList(iterable $items): array
    {
        $rows = [];
        foreach ($items as $item) {
            $rows[] = [[
                'text' => '✨ '.$item['title'],
                'callback_data' => 'add:item:'.$item['id'],
            ]];
        }
        $rows[] = [['text' => '📥 أرسل كل الأذكار الإضافية', 'callback_data' => 'sv:t:z:a', 'style' => 'success']];

        return ['inline_keyboard' => $rows];
    }

    /**
     * @return array<string, mixed>
     */
    public function additionalItem(): array
    {
        return [
            'inline_keyboard' => [
                [['text' => '📋 كل الأذكار الإضافية', 'callback_data' => 'add:list']],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function completion(int $reminderLogId): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => '✅ تم', 'callback_data' => 'reminder:done:'.$reminderLogId],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function followUp(int $reminderLogId, ?string $phase): array
    {
        $start = 'reminder:start:'.$reminderLogId.($phase !== null ? ':'.$phase : '');

        return [
            'inline_keyboard' => [
                [['text' => '📖 هيا نقرأ', 'callback_data' => $start]],
                [['text' => '🔕 لا ترسل لي تذكيرًا ثانيًا', 'callback_data' => 'settings:follow_up_off']],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function reminderNudge(int $reminderLogId, string $kind): array
    {
        $timeCallback = $kind === 'evening' ? 'settings:evening_time' : 'settings:morning_time';

        return [
            'inline_keyboard' => [
                $this->rtl([
                    ['text' => '🌿 مختصر', 'callback_data' => 'reminder:start:'.$reminderLogId.':s'],
                    ['text' => '📖 كامل', 'callback_data' => 'reminder:start:'.$reminderLogId.':a'],
                ]),
                [['text' => '⚙️ تعديل الوقت', 'callback_data' => $timeCallback]],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function todayShortcut(): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => '📖 أذكار اليوم', 'callback_data' => 'today:menu'],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function backCancel(): array
    {
        return ['inline_keyboard' => [$this->backCancelRow()]];
    }

    /**
     * @return array<string, mixed>
     */
    public function backOnly(): array
    {
        return ['inline_keyboard' => [$this->backOnlyRow()]];
    }

    /**
     * @return array<string, mixed>
     */
    public function returningStart(): array
    {
        return [
            'inline_keyboard' => [
                $this->rtl([
                    ['text' => '📖 أذكار اليوم', 'callback_data' => 'today:menu'],
                    ['text' => '⚙️ الإعدادات', 'callback_data' => 'settings:open'],
                ]),
                [['text' => '❓ المساعدة والشرح', 'callback_data' => 'help:menu']],
                [['text' => '🔄 إعادة الإعداد', 'callback_data' => 'onb:reset']],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mainReplyKeyboard(User $user): array
    {
        return [
            'keyboard' => [
                $this->rtl([['text' => '🌅 أذكار الصباح'], ['text' => '🌙 أذكار المساء']]),
                $this->rtl([['text' => '☀️ أذكار الاستيقاظ'], ['text' => '🛌 أذكار النوم']]),
                $this->rtl([['text' => '🕌 أذكار الصلاة'], ['text' => '🌟 أذكار إضافية']]),
                $this->rtl([
                    ['text' => '⚙️ الإعدادات', 'style' => 'primary'],
                    ['text' => '❓ المساعدة والشرح', 'style' => 'primary'],
                ]),
                [['text' => __('bot.feedback_button'), 'style' => 'success']],
            ],
            'resize_keyboard' => true,
            'input_field_placeholder' => 'اختر من الأزرار 👇',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function pauseConfirm(): array
    {
        return [
            'inline_keyboard' => [
                [['text' => '⏸ نعم، أوقف التذكيرات', 'callback_data' => 'settings:pause_yes']],
                [['text' => '↩️ لا، أبقِها مفعّلة', 'callback_data' => 'settings:open']],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resumeConfirm(): array
    {
        return [
            'inline_keyboard' => [
                [['text' => '▶️ نعم، أعد تشغيل التذكيرات', 'callback_data' => 'settings:resume']],
                [['text' => '↩️ لا، أبقِها موقوفة', 'callback_data' => 'settings:open']],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function pausedUndo(): array
    {
        return [
            'inline_keyboard' => [
                [['text' => '↩️ تراجع، أعد تشغيل التذكيرات', 'callback_data' => 'settings:resume']],
                [['text' => '⚙️ الإعدادات', 'callback_data' => 'settings:open']],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resetConfirm(): array
    {
        return [
            'inline_keyboard' => [
                [['text' => '🔄 نعم، أعد الإعداد من البداية', 'callback_data' => 'onb:reset:yes']],
                [['text' => '↩️ لا، أبقِ إعداداتي', 'callback_data' => 'onb:reset:no']],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function backOnlyRow(): array
    {
        return [['text' => '🔙 رجوع', 'callback_data' => 'nav:back']];
    }

    /**
     * رجوع على اليمين، والإلغاء بجانبه. يُستخدم فقط أثناء كتابة حرة من الإعدادات.
     *
     * @return array<int, array<string, mixed>>
     */
    private function backCancelRow(): array
    {
        return $this->rtl([
            ['text' => '🔙 رجوع', 'callback_data' => 'nav:back'],
            ['text' => '❌ إلغاء', 'callback_data' => 'nav:cancel'],
        ]);
    }

    /**
     * تلغرام يرسم أزرار الصف من اليسار إلى اليمين دائمًا، لذا نعكس الترتيب
     * ليظهر أول خيار منطقي في أقصى اليمين للقارئ العربي.
     *
     * @param  array<int, array<string, mixed>>  $row
     * @return array<int, array<string, mixed>>
     */
    private function rtl(array $row): array
    {
        return array_reverse($row);
    }
}
