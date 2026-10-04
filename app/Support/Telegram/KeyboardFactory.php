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
                [['text' => '🚀 ابدأ الإعداد', 'callback_data' => 'onb:begin']],
                [['text' => '💡 شو فكرة البوت؟', 'callback_data' => 'help:topic:idea']],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function helpMenu(): array
    {
        $rows = [];
        foreach (__('bot.help_topics') as $key => $topic) {
            $rows[] = [['text' => $topic['label'], 'callback_data' => 'help:topic:'.$key]];
        }

        return ['inline_keyboard' => $rows];
    }

    /**
     * @return array<string, mixed>
     */
    public function helpTopic(string $key, bool $onboarded): array
    {
        $rows = [];
        if (! $onboarded) {
            $rows[] = [['text' => '🚀 ابدأ الإعداد', 'callback_data' => 'onb:begin']];
        } else {
            $action = match ($key) {
                'read' => ['text' => '📖 أذكار اليوم', 'callback_data' => 'today:menu'],
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
        $cities = config('adhkar.timezones', []);
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
        $rows[] = [['text' => 'دولة أخرى', 'callback_data' => 'onb:tz:custom']];
        if ($includeBack) {
            $rows[] = $this->backOnlyRow();
        }

        return ['inline_keyboard' => $rows];
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
        $rows[] = [['text' => '⏰ وقت آخر (أكتبه بنفسي)', 'callback_data' => "onb:time:{$kind}:custom"]];
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
        if (isset($actions['next'])) {
            $rows[] = [['text' => 'التالي ⬅️', 'callback_data' => $actions['next']]];
        }
        if (isset($actions['prev'])) {
            $rows[] = [['text' => '➡️ السابق', 'callback_data' => $actions['prev']]];
        }
        if (isset($actions['switch_to_full'])) {
            $rows[] = [['text' => '📖 بدّل للورد الكامل', 'callback_data' => $actions['switch_to_full']]];
        }
        if (isset($actions['switch_to_short'])) {
            $rows[] = [['text' => '🌿 بدّل للورد المختصر', 'callback_data' => $actions['switch_to_short']]];
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
    public function settingsMenu(User $user): array
    {
        $reminderButton = $user->reminders_enabled
            ? ['text' => '⏸ إيقاف التذكيرات', 'callback_data' => 'settings:pause_ask']
            : ['text' => '▶️ تشغيل التذكيرات', 'callback_data' => 'settings:resume'];
        $keyboardButton = ($user->keyboard_persistent ?? true)
            ? ['text' => '⌨️ الأزرار السفلية: ظاهرة دائمًا ✅', 'callback_data' => 'settings:keyboard']
            : ['text' => '⌨️ الأزرار السفلية: أظهرها بنفسي', 'callback_data' => 'settings:keyboard'];

        return [
            'inline_keyboard' => [
                [['text' => '🌍 المنطقة', 'callback_data' => 'settings:timezone']],
                $this->rtl([
                    ['text' => '🌅 وقت الصباح', 'callback_data' => 'settings:morning_time'],
                    ['text' => '🌙 وقت المساء', 'callback_data' => 'settings:evening_time'],
                ]),
                [$keyboardButton],
                [$reminderButton],
                [['text' => '🔙 رجوع', 'callback_data' => 'settings:close']],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  string|null  $period  morning, evening or night from DayPeriod; that choice goes first and is marked
     */
    public function todayMenu(?string $period = null): array
    {
        $now = ' · ⭐ وقتها هلق';
        $morning = ['text' => '🌅 أذكار الصباح', 'callback_data' => 'today:morning'];
        $evening = ['text' => '🌙 أذكار المساء', 'callback_data' => 'today:evening'];

        $choices = match ($period) {
            'morning' => [['text' => $morning['text'].$now] + $morning, $evening],
            'evening' => [['text' => $evening['text'].$now] + $evening, $morning],
            'night' => [
                ['text' => '🌙 أذكار النوم'.$now, 'callback_data' => 'today:bedtime'],
                $evening,
                $morning,
            ],
            default => [$morning, $evening],
        };

        $rows = array_map(fn (array $button) => [$button], $choices);
        $rows[] = [['text' => '🌟 أذكار إضافية', 'callback_data' => 'add:list']];

        return ['inline_keyboard' => $rows];
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
                [['text' => '✅ تمام، هيك مناسب', 'callback_data' => 'onb:quick:yes']],
                [['text' => '⏰ بدي أوقات تانية', 'callback_data' => 'onb:quick:custom']],
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
     * @return array<string, mixed>
     */
    public function additionalGroups(): array
    {
        $rows = [];
        foreach (config('adhkar.additional_groups', []) as $group) {
            $rows[] = [[
                'text' => $group['label'],
                'callback_data' => 'add:group:'.$group['key'],
            ]];
        }
        $rows[] = [['text' => '📚 جميع الأذكار', 'callback_data' => 'add:group:all']];
        $rows[] = [['text' => '🔙 رجوع', 'callback_data' => 'add:back']];

        return ['inline_keyboard' => $rows];
    }

    /**
     * @param  iterable<int, array{id:int,title:string}>  $items
     * @return array<string, mixed>
     */
    public function additionalList(iterable $items, string $groupKey): array
    {
        $rows = [];
        foreach ($items as $item) {
            $rows[] = [[
                'text' => $item['title'],
                'callback_data' => 'add:item:'.$item['id'].':'.$groupKey,
            ]];
        }
        $rows[] = [['text' => '🔙 رجوع', 'callback_data' => 'add:list']];

        return ['inline_keyboard' => $rows];
    }

    /**
     * @return array<string, mixed>
     */
    public function additionalItem(?string $groupKey = null): array
    {
        $back = $groupKey !== null && $groupKey !== '' ? 'add:group:'.$groupKey : 'add:list';

        return [
            'inline_keyboard' => [[
                ['text' => '🔙 رجوع', 'callback_data' => $back],
            ]],
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
        $keyboard = [
            'keyboard' => [
                $this->rtl([['text' => '📖 أذكار اليوم'], ['text' => '🌟 أذكار إضافية']]),
                $this->rtl([['text' => '🌙 أذكار النوم'], ['text' => '🌅 أذكار الاستيقاظ']]),
                $this->rtl([['text' => '⚙️ الإعدادات'], ['text' => '❓ المساعدة والشرح']]),
            ],
            'resize_keyboard' => true,
        ];

        if ($user->keyboard_persistent ?? true) {
            $keyboard['is_persistent'] = true;
            $keyboard['input_field_placeholder'] = 'اختر من الأزرار 👇';
        }

        return $keyboard;
    }

    /**
     * @return array<string, mixed>
     */
    public function pauseConfirm(): array
    {
        return [
            'inline_keyboard' => [
                [['text' => '⏸ نعم، أوقف التذكيرات', 'callback_data' => 'settings:pause_yes']],
                [['text' => '↩️ لا، خليها شغّالة', 'callback_data' => 'settings:open']],
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
                [['text' => '↩️ تراجع، رجّع التذكيرات', 'callback_data' => 'settings:resume']],
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
                [['text' => '↩️ لا، خلّي إعداداتي', 'callback_data' => 'onb:reset:no']],
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
