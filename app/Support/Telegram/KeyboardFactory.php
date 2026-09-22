<?php

namespace App\Support\Telegram;

use App\Models\User;

class KeyboardFactory
{
    /**
     * @return array<string, mixed>
     */
    public function timezoneOptions(): array
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
                $rows[] = $row;
                $row = [];
            }
        }
        if ($row !== []) {
            $rows[] = $row;
        }
        $rows[] = [['text' => 'مدينة أخرى', 'callback_data' => 'onb:tz:custom']];

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
                'text' => $time,
                'callback_data' => "onb:time:{$kind}:{$encoded}",
            ];
            if (count($row) === 3) {
                $rows[] = $row;
                $row = [];
            }
        }
        if ($row !== []) {
            $rows[] = $row;
        }
        $rows[] = [['text' => 'وقت آخر', 'callback_data' => "onb:time:{$kind}:custom"]];

        return ['inline_keyboard' => $rows];
    }

    /**
     * @return array<string, mixed>
     */
    public function modeOptions(): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => 'مختصر', 'callback_data' => 'onb:mode:short'],
                ['text' => 'كامل', 'callback_data' => 'onb:mode:full'],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function settingsMenu(User $user): array
    {
        $reminderLabel = $user->reminders_enabled ? 'إيقاف التذكير' : 'تشغيل التذكير';

        return [
            'inline_keyboard' => [
                [['text' => 'المنطقة الزمنية', 'callback_data' => 'settings:timezone']],
                [
                    ['text' => 'وقت الصباح', 'callback_data' => 'settings:morning_time'],
                    ['text' => 'وقت المساء', 'callback_data' => 'settings:evening_time'],
                ],
                [
                    ['text' => 'مختصر', 'callback_data' => 'settings:mode:short'],
                    ['text' => 'كامل', 'callback_data' => 'settings:mode:full'],
                ],
                [['text' => $reminderLabel, 'callback_data' => 'settings:toggle_reminders']],
                [['text' => 'إغلاق', 'callback_data' => 'settings:close']],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function todayMenu(): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => '🌅 صباح', 'callback_data' => 'today:morning'],
                ['text' => '🌙 مساء', 'callback_data' => 'today:evening'],
                ['text' => '📖 إضافية', 'callback_data' => 'add:list'],
            ]],
        ];
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
                'text' => $item['title'],
                'callback_data' => 'add:item:'.$item['id'],
            ]];
        }
        $rows[] = [['text' => '🔙 رجوع', 'callback_data' => 'add:back']];

        return ['inline_keyboard' => $rows];
    }

    /**
     * @return array<string, mixed>
     */
    public function additionalItem(): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => '🔙 رجوع', 'callback_data' => 'add:list'],
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
    public function returningStart(): array
    {
        return [
            'inline_keyboard' => [
                [['text' => 'الإعدادات', 'callback_data' => 'settings:open']],
                [['text' => 'إعادة الضبط', 'callback_data' => 'onb:reset']],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mainReplyKeyboard(User $user): array
    {
        $pauseOrResume = $user->reminders_enabled ? 'إيقاف التذكير' : 'استئناف التذكير';

        return [
            'keyboard' => [
                [['text' => 'أذكار اليوم'], ['text' => 'أذكار إضافية']],
                [['text' => 'الإعدادات'], ['text' => $pauseOrResume]],
                [['text' => 'مساعدة']],
            ],
            'resize_keyboard' => true,
        ];
    }
}
