<?php

namespace App\Services\Telegram;

use App\Enums\AdhkarMode;
use App\Exceptions\InvalidTimeException;
use App\Models\GroupChat;
use App\Services\ReminderScheduleCalculator;
use App\Support\TimezoneCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Everything the bot does inside groups: daily posts are set up here by the group's admins.
 */
class GroupChatService
{
    private const MORNING_TIMES = ['04:00', '04:30', '05:00', '05:30', '06:00', '06:30', '07:00', '07:30', '08:00', '08:30', '09:00', '09:30'];

    private const EVENING_TIMES = ['15:00', '15:30', '16:00', '16:30', '17:00', '17:30', '18:00', '18:30', '19:00', '19:30', '20:00', '20:30'];

    private const ADMIN_CACHE_SECONDS = 60;

    private const PROMPT_CACHE_SECONDS = 86400;

    // Sent by Telegram as the author of messages from anonymous group admins.
    private const ANONYMOUS_ADMIN_BOT_ID = 1087968824;

    public function __construct(private readonly TelegramClient $telegram) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function isGroupUpdate(array $payload): bool
    {
        if (isset($payload['channel_post']) || isset($payload['edited_channel_post'])) {
            return true;
        }

        $type = data_get($payload, 'message.chat.type')
            ?? data_get($payload, 'edited_message.chat.type')
            ?? data_get($payload, 'callback_query.message.chat.type')
            ?? data_get($payload, 'my_chat_member.chat.type');

        return $type !== null && $type !== 'private';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        if (isset($payload['my_chat_member'])) {
            $this->handleMembership($payload['my_chat_member']);
        } elseif (isset($payload['callback_query'])) {
            $this->handleCallback($payload['callback_query']);
        } elseif (isset($payload['message'])) {
            $this->handleMessage($payload['message']);
        }
    }

    /**
     * @param  array<string, mixed>  $update
     */
    private function handleMembership(array $update): void
    {
        $chat = $update['chat'] ?? [];
        if (! in_array($chat['type'] ?? null, ['group', 'supergroup'], true)) {
            return;
        }

        $status = data_get($update, 'new_chat_member.status');
        $wasIn = in_array(data_get($update, 'old_chat_member.status'), ['member', 'administrator', 'restricted'], true);
        $isIn = in_array($status, ['member', 'administrator', 'restricted'], true);

        $group = GroupChat::query()->firstOrNew(['chat_id' => (int) $chat['id']]);
        $group->title = $chat['title'] ?? $group->title;
        $group->bot_member = $isIn;
        $group->reschedule();
        $group->save();

        if ($isIn && ! $wasIn) {
            $this->send($group->chat_id, __('bot.group.welcome'), [
                'inline_keyboard' => [[['text' => __('bot.group.setup_button'), 'callback_data' => 'g:menu']]],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function handleMessage(array $message): void
    {
        $chatId = (int) data_get($message, 'chat.id');

        if (isset($message['migrate_to_chat_id'])) {
            GroupChat::query()->where('chat_id', $chatId)->update(['chat_id' => (int) $message['migrate_to_chat_id']]);

            return;
        }

        $replyTo = (int) data_get($message, 'reply_to_message.message_id');
        $prompt = $replyTo === 0 ? null : Cache::get($this->promptKey($chatId, $replyTo));
        if (is_string($prompt)) {
            $this->handleReply($message, $prompt, $replyTo);

            return;
        }

        $command = $this->command((string) ($message['text'] ?? ''));
        if (! in_array($command, ['/settings', '/start'], true)) {
            return;
        }

        $group = $this->group($chatId, data_get($message, 'chat.title'));
        if (! $this->senderIsAdmin($message)) {
            $this->send($chatId, __('bot.group.admins_only'), [], ['reply_to_message_id' => (int) ($message['message_id'] ?? 0)]);

            return;
        }

        $this->send($chatId, $this->settingsText($group), $this->settingsKeyboard($group));
    }

    /**
     * An admin's typed answer to one of the bot's "reply with…" prompts.
     *
     * @param  array<string, mixed>  $message
     */
    private function handleReply(array $message, string $kind, int $promptId): void
    {
        $chatId = (int) data_get($message, 'chat.id');
        $reply = ['reply_to_message_id' => (int) ($message['message_id'] ?? 0)];

        if (! $this->senderIsAdmin($message)) {
            $this->send($chatId, __('bot.group.admins_only'), [], $reply);

            return;
        }

        Cache::forget($this->promptKey($chatId, $promptId));
        $group = $this->group($chatId, data_get($message, 'chat.title'));
        $text = trim((string) ($message['text'] ?? ''));

        if ($kind === 'tz') {
            $this->saveTypedCountry($group, $text, $reply);
        } else {
            $this->saveTypedTime($group, $kind, $text, $reply);
        }
    }

    /**
     * @param  array<string, mixed>  $reply
     */
    private function saveTypedTime(GroupChat $group, string $kind, string $text, array $reply): void
    {
        $period = $kind === 'mt' ? 'morning' : 'evening';

        try {
            $result = ReminderScheduleCalculator::classifyTime($text, $period);
        } catch (InvalidTimeException $exception) {
            $error = __('bot.'.$exception->reason, [
                'input' => e($text),
                'example' => $kind === 'mt' ? '5:45 الصبح' : '6:30 المسا',
            ]);
            $this->prompt($group->chat_id, $kind, null, $error, $reply);

            return;
        } catch (InvalidArgumentException) {
            $this->prompt($group->chat_id, $kind, null, __('bot.invalid_time'), $reply);

            return;
        }

        if ($result['action'] === 'confirm') {
            $buttons = array_map(fn (array $option) => [
                'text' => $option['label'],
                'callback_data' => "g:{$kind}:".substr($option['time'], 0, 5),
            ], $result['options']);
            $this->send($group->chat_id, __('bot.group.pick_period'), $this->grid($buttons, 2), $reply);

            return;
        }

        $group->{$kind === 'mt' ? 'morning_time' : 'evening_time'} = substr($result['time'], 0, 5);
        $this->saveAndShow($group, $reply);
    }

    /**
     * @param  array<string, mixed>  $reply
     */
    private function saveTypedCountry(GroupChat $group, string $text, array $reply): void
    {
        $multiZone = TimezoneCatalog::multiZone($text);
        if ($multiZone !== null) {
            $regionIds = array_column($multiZone['regions'], 'id');
            $buttons = [];
            foreach ($this->timezones() as $index => $zone) {
                if (in_array($zone['id'], $regionIds, true)) {
                    $buttons[] = ['text' => $zone['label'], 'callback_data' => 'g:tz:'.$index];
                }
            }
            $this->send($group->chat_id, __('bot.group.pick_region', ['country' => e($multiZone['label'])]), $this->grid($buttons, 2), $reply);

            return;
        }

        $resolved = TimezoneCatalog::resolveWithLabel($text);
        if ($resolved === null) {
            $this->prompt($group->chat_id, 'tz', null, __('bot.group.country_not_found', ['input' => e($text)]), $reply);

            return;
        }

        if ($group->timezone === null) {
            $group->enabled = true;
        }
        $group->timezone = $resolved['id'];
        $this->saveAndShow($group, $reply);
    }

    /**
     * @param  array<string, mixed>  $reply
     */
    private function saveAndShow(GroupChat $group, array $reply): void
    {
        $group->reschedule();
        $group->save();

        $this->send($group->chat_id, __('bot.group.saved')."\n\n".$this->settingsText($group), $this->settingsKeyboard($group), $reply);
    }

    /**
     * Asks for a typed value. Telegram delivers replies to the bot's own messages even in privacy mode,
     * and force_reply opens the reply box for the mentioned admin or the user being replied to.
     *
     * @param  array<string, mixed>|null  $admin
     * @param  array<string, mixed>  $extra
     */
    private function prompt(int $chatId, string $kind, ?array $admin, ?string $lead = null, array $extra = []): void
    {
        $text = __('bot.group.ask_'.$kind);
        if ($admin !== null && (int) ($admin['id'] ?? 0) !== 0) {
            $text = sprintf('<a href="tg://user?id=%d">%s</a> ', (int) $admin['id'], e($admin['first_name'] ?? '')).$text;
        }
        if ($lead !== null) {
            $text = $lead."\n\n".$text;
        }

        try {
            $response = $this->telegram->sendMessage($chatId, $text, [
                'force_reply' => true,
                'selective' => true,
                'input_field_placeholder' => __('bot.group.placeholder_'.$kind),
            ], $extra);
        } catch (Throwable $exception) {
            Log::channel('telegram')->warning('Group prompt failed', [
                'chat_id' => $chatId,
                'error' => TelegramClient::redact($exception->getMessage()),
            ]);

            return;
        }

        $messageId = (int) data_get($response, 'result.message_id');
        if ($messageId !== 0) {
            Cache::put($this->promptKey($chatId, $messageId), $kind, self::PROMPT_CACHE_SECONDS);
        }
    }

    private function promptKey(int $chatId, int $messageId): string
    {
        return "group_prompt:{$chatId}:{$messageId}";
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function senderIsAdmin(array $message): bool
    {
        $chatId = (int) data_get($message, 'chat.id');
        $anonymousAdmin = (int) data_get($message, 'sender_chat.id') === $chatId
            || (int) data_get($message, 'from.id') === self::ANONYMOUS_ADMIN_BOT_ID;

        return $anonymousAdmin || $this->isAdmin($chatId, (int) data_get($message, 'from.id'));
    }

    /**
     * @param  array<string, mixed>  $callback
     */
    private function handleCallback(array $callback): void
    {
        $callbackId = (string) ($callback['id'] ?? '');
        $data = (string) ($callback['data'] ?? '');
        $chatId = (int) data_get($callback, 'message.chat.id');
        $messageId = (int) data_get($callback, 'message.message_id');

        if (! str_starts_with($data, 'g:')) {
            $this->answer($callbackId);

            return;
        }

        if (! $this->isAdmin($chatId, (int) data_get($callback, 'from.id'))) {
            $this->answer($callbackId, __('bot.group.admins_only'), true);

            return;
        }

        $group = $this->group($chatId, data_get($callback, 'message.chat.title'));
        [, $action, $value] = array_pad(explode(':', $data, 3), 3, null);
        $timezones = $this->timezones();

        if ($action === 'ask' && in_array($value, ['tz', 'mt', 'et'], true)) {
            $this->answer($callbackId);
            $this->prompt($chatId, $value, (array) data_get($callback, 'from', []));

            return;
        }

        $screen = match ($action) {
            'tz' => $value === null ? 'country' : 'menu',
            'mt' => $value === null ? 'morning' : 'menu',
            'et' => $value === null ? 'evening' : 'menu',
            default => 'menu',
        };

        $changed = true;
        if ($action === 'tz' && $value !== null && isset($timezones[(int) $value])) {
            if ($group->timezone === null) {
                $group->enabled = true;
            }
            $group->timezone = $timezones[(int) $value]['id'];
        } elseif ($action === 'mt' && $this->isTime($value)) {
            $group->morning_time = $value;
        } elseif ($action === 'et' && $this->isTime($value)) {
            $group->evening_time = $value;
        } elseif ($action === 'mode') {
            $group->mode = $group->mode === AdhkarMode::Full ? AdhkarMode::Short : AdhkarMode::Full;
        } elseif ($action === 'toggle' && $group->timezone !== null) {
            $group->enabled = ! $group->enabled;
        } else {
            $changed = false;
        }

        if ($changed) {
            $group->reschedule();
            $group->save();
        }
        $this->answer($callbackId, $changed ? __('bot.group.saved') : '');

        [$text, $keyboard] = match ($screen) {
            'country' => [__('bot.group.pick_country'), $this->countryKeyboard($group)],
            'morning' => [__('bot.group.pick_morning'), $this->timeKeyboard('mt', self::MORNING_TIMES, $group->morning_time)],
            'evening' => [__('bot.group.pick_evening'), $this->timeKeyboard('et', self::EVENING_TIMES, $group->evening_time)],
            default => [$this->settingsText($group), $this->settingsKeyboard($group)],
        };

        try {
            $this->telegram->editMessageText($chatId, $messageId, $text, $keyboard);
        } catch (Throwable) {
            // The message may be too old to edit or already show this screen.
        }
    }

    private function settingsText(GroupChat $group): string
    {
        $status = match (true) {
            $group->timezone === null => __('bot.group.status_no_country'),
            $group->enabled => __('bot.group.status_on'),
            default => __('bot.group.status_off'),
        };

        return __('bot.group.settings', [
            'status' => $status,
            'country' => e($this->timezoneLabel($group->timezone) ?? __('bot.group.country_missing')),
            'morning' => ReminderScheduleCalculator::format12Hour($group->morning_time),
            'evening' => ReminderScheduleCalculator::format12Hour($group->evening_time),
            'mode' => $this->modeLabel($group),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsKeyboard(GroupChat $group): array
    {
        $rows = [
            [['text' => __('bot.group.country_button'), 'callback_data' => 'g:tz']],
            array_reverse([
                ['text' => __('bot.group.morning_button'), 'callback_data' => 'g:mt'],
                ['text' => __('bot.group.evening_button'), 'callback_data' => 'g:et'],
            ]),
            [['text' => __('bot.group.mode_button', ['mode' => $this->modeLabel($group)]), 'callback_data' => 'g:mode']],
        ];
        if ($group->timezone !== null) {
            $rows[] = [['text' => $group->enabled ? __('bot.group.pause_button') : __('bot.group.resume_button'), 'callback_data' => 'g:toggle']];
        }

        return ['inline_keyboard' => $rows];
    }

    /**
     * @return array<string, mixed>
     */
    private function countryKeyboard(GroupChat $group): array
    {
        $buttons = [];
        foreach ($this->timezones() as $index => $zone) {
            $mark = $zone['id'] === $group->timezone ? '✓ ' : '';
            $buttons[] = ['text' => $mark.$zone['label'], 'callback_data' => 'g:tz:'.$index];
        }

        return $this->grid($buttons, 3, [['text' => __('bot.group.other_country_button'), 'callback_data' => 'g:ask:tz']]);
    }

    /**
     * @param  list<string>  $times
     * @return array<string, mixed>
     */
    private function timeKeyboard(string $action, array $times, string $current): array
    {
        $buttons = array_map(fn (string $time) => [
            'text' => ($time === $current ? '✓ ' : '').ReminderScheduleCalculator::format12Hour($time),
            'callback_data' => "g:{$action}:{$time}",
        ], $times);

        $custom = in_array($current, $times, true) ? '' : '✓ '.ReminderScheduleCalculator::format12Hour($current).' · ';

        return $this->grid($buttons, 3, [['text' => $custom.__('bot.group.other_time_button'), 'callback_data' => "g:ask:{$action}"]]);
    }

    private function isTime(?string $value): bool
    {
        return $value !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    /**
     * Telegram lays rows out left to right, so each row is reversed for Arabic readers.
     *
     * @param  list<array<string, string>>  $buttons
     * @param  list<array<string, string>>|null  $extraRow
     * @return array<string, mixed>
     */
    private function grid(array $buttons, int $perRow, ?array $extraRow = null): array
    {
        $rows = array_map(fn (array $row) => array_reverse($row), array_chunk($buttons, $perRow));
        if ($extraRow !== null) {
            $rows[] = $extraRow;
        }
        $rows[] = [['text' => __('bot.group.back_button'), 'callback_data' => 'g:menu']];

        return ['inline_keyboard' => $rows];
    }

    /**
     * Countries from the timezone list, with multi-zone countries split into their regions.
     *
     * @return list<array{label:string,id:string}>
     */
    private function timezones(): array
    {
        $zones = [];
        foreach (config('adhkar.timezones', []) as $country) {
            if (isset($country['id'])) {
                $zones[] = ['label' => $country['label'], 'id' => $country['id']];
            }
            foreach ($country['regions'] ?? [] as $region) {
                $zones[] = ['label' => $country['label'].' - '.$region['label'], 'id' => $region['id']];
            }
        }

        return $zones;
    }

    private function timezoneLabel(?string $timezone): ?string
    {
        foreach ($this->timezones() as $zone) {
            if ($zone['id'] === $timezone) {
                return $zone['label'];
            }
        }

        return $timezone === null ? null : str_replace('_', ' ', (string) last(explode('/', $timezone)));
    }

    private function modeLabel(GroupChat $group): string
    {
        return $group->mode === AdhkarMode::Full ? __('bot.mode_full') : __('bot.mode_short');
    }

    private function group(int $chatId, ?string $title): GroupChat
    {
        $group = GroupChat::query()->firstOrCreate(['chat_id' => $chatId], ['title' => $title]);
        if (! $group->bot_member) {
            $group->bot_member = true;
            $group->reschedule();
            $group->save();
        }

        return $group;
    }

    private function isAdmin(int $chatId, int $userId): bool
    {
        if ($userId === 0) {
            return false;
        }

        return Cache::remember("group_admin:{$chatId}:{$userId}", self::ADMIN_CACHE_SECONDS, function () use ($chatId, $userId) {
            try {
                $status = data_get($this->telegram->getChatMember($chatId, $userId), 'result.status');
            } catch (Throwable) {
                return false;
            }

            return in_array($status, ['creator', 'administrator'], true);
        });
    }

    /**
     * The command without its @botname suffix, or null when it is addressed to a different bot.
     */
    private function command(string $text): ?string
    {
        if (! str_starts_with($text, '/')) {
            return null;
        }

        [$command, $target] = array_pad(explode('@', explode(' ', $text)[0], 2), 2, null);
        $username = $this->botUsername();
        if ($target !== null && $username !== null && strcasecmp($target, $username) !== 0) {
            return null;
        }

        return strtolower($command);
    }

    private function botUsername(): ?string
    {
        $username = Cache::get('telegram:bot_username');
        if (is_string($username)) {
            return $username;
        }

        try {
            $username = data_get($this->telegram->getMe(), 'result.username');
        } catch (Throwable) {
            return null;
        }
        if (! is_string($username) || $username === '') {
            return null;
        }
        Cache::forever('telegram:bot_username', $username);

        return $username;
    }

    /**
     * @param  array<string, mixed>  $markup
     * @param  array<string, mixed>  $extra
     */
    private function send(int $chatId, string $text, array $markup = [], array $extra = []): void
    {
        try {
            $this->telegram->sendMessage($chatId, $text, $markup, $extra);
        } catch (Throwable $exception) {
            Log::channel('telegram')->warning('Group message failed', [
                'chat_id' => $chatId,
                'error' => TelegramClient::redact($exception->getMessage()),
            ]);
        }
    }

    private function answer(string $callbackId, string $text = '', bool $alert = false): void
    {
        if ($callbackId === '') {
            return;
        }

        try {
            $this->telegram->answerCallbackQuery($callbackId, $text, $alert);
        } catch (Throwable) {
            // The spinner stops on its own.
        }
    }
}
