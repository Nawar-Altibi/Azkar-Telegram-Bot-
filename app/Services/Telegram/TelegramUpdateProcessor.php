<?php

namespace App\Services\Telegram;

use App\Enums\AdhkarCategory;
use App\Enums\AdhkarMode;
use App\Enums\OnboardingState;
use App\Enums\ReminderLogStatus;
use App\Enums\ReminderType;
use App\Models\AdhkarCollection;
use App\Models\AdhkarItem;
use App\Models\ReminderLog;
use App\Models\User;
use App\Services\AdhkarContentService;
use App\Services\OnboardingService;
use App\Services\ReminderScheduleCalculator;
use App\Services\UserSettingsService;
use App\Support\Telegram\KeyboardFactory;
use App\Support\Telegram\TelegramUpdate as IncomingUpdate;
use App\Support\Telegram\TelegramUser;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class TelegramUpdateProcessor
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly OnboardingService $onboarding,
        private readonly UserSettingsService $settings,
        private readonly AdhkarContentService $content,
        private readonly KeyboardFactory $keyboards,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        $update = IncomingUpdate::fromArray($payload);
        $from = $update->from();
        $chatId = $update->chatId();

        if ($from === null || $chatId === null) {
            return;
        }

        $user = $this->findOrCreateUser($from, $chatId);
        $user->last_interaction_at = now();
        $user->save();

        if ($update->callbackQuery) {
            $this->handleCallback($user, $update);

            return;
        }

        if ($update->message?->text) {
            $this->handleMessage($user, $update);
        }
    }

    private function findOrCreateUser(TelegramUser $from, int $chatId): User
    {
        $user = User::query()->where('telegram_id', $from->id)->first();

        if ($user === null) {
            return User::query()->create([
                'telegram_id' => $from->id,
                'chat_id' => $chatId,
                'first_name' => $from->firstName,
                'username' => $from->username,
                'language_code' => $from->languageCode,
                'onboarding_state' => OnboardingState::New,
                'reminders_enabled' => false,
            ]);
        }

        $user->fill([
            'chat_id' => $chatId,
            'first_name' => $from->firstName,
            'username' => $from->username,
            'language_code' => $from->languageCode,
        ]);

        return $user;
    }

    private function handleMessage(User $user, IncomingUpdate $update): void
    {
        $text = trim((string) $update->message?->text);
        $command = $this->extractCommand($text);

        if ($command !== null) {
            $this->handleCommand($user, $command);

            return;
        }

        if ($this->handleReplyKeyboard($user, $text)) {
            return;
        }

        if ($user->awaiting_input) {
            $this->handleAwaitingInput($user, $text);

            return;
        }

        $this->reply($user, __('bot.unknown'), $this->keyboards->mainReplyKeyboard($user));
    }

    private function handleCommand(User $user, string $command): void
    {
        match ($command) {
            '/start' => $this->sendResponse($user, $this->onboarding->start($user)),
            '/settings' => $this->sendSettings($user),
            '/help' => $this->reply($user, __('bot.help'), $this->keyboards->mainReplyKeyboard($user)),
            '/pause' => $this->handlePause($user),
            '/resume' => $this->handleResume($user),
            '/today' => $this->reply($user, __('bot.today_prompt'), $this->keyboards->todayMenu()),
            default => $this->reply($user, __('bot.unknown')),
        };
    }

    private function handleReplyKeyboard(User $user, string $text): bool
    {
        return match ($text) {
            'أذكار اليوم' => tap(true, fn () => $this->reply($user, __('bot.today_prompt'), $this->keyboards->todayMenu())),
            'أذكار إضافية' => tap(true, fn () => $this->sendAdditionalList($user)),
            'الإعدادات' => tap(true, fn () => $this->sendSettings($user)),
            'إيقاف التذكير' => tap(true, fn () => $this->handlePause($user)),
            'استئناف التذكير' => tap(true, fn () => $this->handleResume($user)),
            'مساعدة' => tap(true, fn () => $this->reply($user, __('bot.help'), $this->keyboards->mainReplyKeyboard($user))),
            default => false,
        };
    }

    private function handleAwaitingInput(User $user, string $text): void
    {
        try {
            match ($user->awaiting_input) {
                'onboarding:timezone_custom', 'settings:timezone_custom' => $this->handleCustomTimezone($user, $text),
                'onboarding:morning_time_custom', 'settings:morning_time_custom' => $this->handleCustomTime($user, $text, 'morning'),
                'onboarding:evening_time_custom', 'settings:evening_time_custom' => $this->handleCustomTime($user, $text, 'evening'),
                default => $this->reply($user, __('bot.unknown')),
            };
        } catch (InvalidArgumentException) {
            $this->reply($user, __('bot.invalid_time'));
        }
    }

    private function handleCustomTimezone(User $user, string $text): void
    {
        $timezone = $this->onboarding->resolveTimezoneInput($text);
        if ($timezone === null) {
            $this->reply($user, __('bot.invalid_timezone'));

            return;
        }

        $fromSettings = str_starts_with((string) $user->awaiting_input, 'settings:');
        if ($fromSettings) {
            $this->settings->updateTimezone($user, $timezone);
            $user->awaiting_input = null;
            $user->save();
            $this->reply($user, __('bot.timezone_saved', ['timezone' => $timezone])."\n\n".$this->onboarding->summary($user->fresh()));

            return;
        }

        $this->sendResponse($user, $this->onboarding->handleTimezone($user, $timezone));
    }

    private function handleCustomTime(User $user, string $text, string $kind): void
    {
        $fromSettings = str_starts_with((string) $user->awaiting_input, 'settings:');
        if ($fromSettings) {
            if ($kind === 'morning') {
                $this->settings->updateMorningTime($user, $text);
            } else {
                $this->settings->updateEveningTime($user, $text);
            }
            $user->awaiting_input = null;
            $user->save();
            $time = $kind === 'morning' ? $user->fresh()->formattedMorningTime() : $user->fresh()->formattedEveningTime();
            $message = $kind === 'morning'
                ? __('bot.morning_saved', ['time' => $time])
                : __('bot.evening_saved', ['time' => $time]);
            $this->reply($user, $message."\n\n".$this->onboarding->summary($user->fresh()));

            return;
        }

        $response = $kind === 'morning'
            ? $this->onboarding->handleMorningTime($user, $text)
            : $this->onboarding->handleEveningTime($user, $text);
        $this->sendResponse($user, $response);
    }

    private function handleCallback(User $user, IncomingUpdate $update): void
    {
        $callback = $update->callbackQuery;
        if ($callback === null) {
            return;
        }

        $data = $callback->data;

        try {
            $this->routeCallback($user, $data, $callback->id, $callback->message?->messageId);
        } catch (Throwable $exception) {
            Log::channel('telegram')->warning('Callback handling failed', [
                'data' => $data,
                'error' => $exception->getMessage(),
            ]);
            $this->safeAnswer($callback->id, __('bot.error'));
        }
    }

    private function routeCallback(User $user, string $data, string $callbackId, ?int $messageId): void
    {
        if ($data === 'noop') {
            $this->safeAnswer($callbackId);

            return;
        }

        if ($data === 'onb:reset') {
            $this->sendResponse($user, $this->onboarding->reset($user));
            $this->safeAnswer($callbackId);

            return;
        }

        if (str_starts_with($data, 'onb:tz:')) {
            $this->handleTimezoneCallback($user, $data, $callbackId);

            return;
        }

        if (str_starts_with($data, 'onb:time:')) {
            $this->handleTimeCallback($user, $data, $callbackId);

            return;
        }

        if ($data === 'onb:mode:short' || $data === 'onb:mode:full') {
            $mode = $data === 'onb:mode:full' ? AdhkarMode::Full : AdhkarMode::Short;
            $this->sendResponse($user, $this->onboarding->handleMode($user, $mode));
            $this->safeAnswer($callbackId);

            return;
        }

        if (str_starts_with($data, 'settings:')) {
            $this->handleSettingsCallback($user, $data, $callbackId, $messageId);

            return;
        }

        if ($data === 'today:morning' || $data === 'today:evening') {
            $type = $data === 'today:morning' ? ReminderType::Morning : ReminderType::Evening;
            $this->sendCollection($user, $type);
            $this->safeAnswer($callbackId);

            return;
        }

        if (str_starts_with($data, 'add:')) {
            $this->handleAdditionalCallback($user, $data, $callbackId);
            $this->safeAnswer($callbackId);

            return;
        }

        if (str_starts_with($data, 'reminder:done:')) {
            $this->handleCompletion($user, (int) substr($data, strlen('reminder:done:')), $callbackId);

            return;
        }

        $this->safeAnswer($callbackId, __('bot.unknown'));
    }

    private function handleTimezoneCallback(User $user, string $data, string $callbackId): void
    {
        $token = substr($data, strlen('onb:tz:'));
        if ($token === 'custom') {
            $awaiting = $user->onboarding_state === OnboardingState::Completed
                ? 'settings:timezone_custom'
                : 'onboarding:timezone_custom';
            $user->awaiting_input = $awaiting;
            $user->save();
            $this->reply($user, __('bot.ask_timezone_custom'));
            $this->safeAnswer($callbackId);

            return;
        }

        $cities = config('adhkar.timezones', []);
        $timezone = $cities[(int) $token]['id'] ?? null;
        if (! $timezone) {
            $this->safeAnswer($callbackId, __('bot.invalid_timezone'));

            return;
        }

        if ($user->onboarding_state === OnboardingState::Completed) {
            $this->settings->updateTimezone($user, $timezone);
            $this->reply($user, __('bot.timezone_saved', ['timezone' => $timezone])."\n\n".$this->onboarding->summary($user->fresh()));
        } else {
            $this->sendResponse($user, $this->onboarding->handleTimezone($user, $timezone));
        }
        $this->safeAnswer($callbackId);
    }

    private function handleTimeCallback(User $user, string $data, string $callbackId): void
    {
        $parts = explode(':', $data);
        $kind = $parts[2] ?? 'morning';
        $value = $parts[3] ?? '';

        if ($value === 'custom') {
            $prefix = $user->onboarding_state === OnboardingState::Completed ? 'settings' : 'onboarding';
            $user->awaiting_input = "{$prefix}:{$kind}_time_custom";
            $user->save();
            $this->reply($user, __('bot.ask_time_custom'));
            $this->safeAnswer($callbackId);

            return;
        }

        $time = substr($value, 0, 2).':'.substr($value, 2, 2);
        if (! ReminderScheduleCalculator::isValidTime($time)) {
            $this->safeAnswer($callbackId, __('bot.invalid_time'));

            return;
        }

        if ($user->onboarding_state === OnboardingState::Completed) {
            if ($kind === 'morning') {
                $this->settings->updateMorningTime($user, $time);
                $this->reply($user, __('bot.morning_saved', ['time' => $time])."\n\n".$this->onboarding->summary($user->fresh()));
            } else {
                $this->settings->updateEveningTime($user, $time);
                $this->reply($user, __('bot.evening_saved', ['time' => $time])."\n\n".$this->onboarding->summary($user->fresh()));
            }
        } else {
            $response = $kind === 'morning'
                ? $this->onboarding->handleMorningTime($user, $time)
                : $this->onboarding->handleEveningTime($user, $time);
            $this->sendResponse($user, $response);
        }
        $this->safeAnswer($callbackId);
    }

    private function handleSettingsCallback(User $user, string $data, string $callbackId, ?int $messageId): void
    {
        if ($data === 'settings:open') {
            $this->sendSettings($user);
            $this->safeAnswer($callbackId);

            return;
        }

        if ($data === 'settings:close') {
            $this->safeAnswer($callbackId, __('bot.closed'));

            return;
        }

        if ($data === 'settings:timezone') {
            $this->reply($user, __('bot.ask_timezone'), $this->keyboards->timezoneOptions());
            $this->safeAnswer($callbackId);

            return;
        }

        if ($data === 'settings:morning_time') {
            $this->reply($user, __('bot.ask_morning_time'), $this->keyboards->timePresets('morning'));
            $this->safeAnswer($callbackId);

            return;
        }

        if ($data === 'settings:evening_time') {
            $this->reply($user, __('bot.ask_evening_time'), $this->keyboards->timePresets('evening'));
            $this->safeAnswer($callbackId);

            return;
        }

        if ($data === 'settings:mode:short' || $data === 'settings:mode:full') {
            $mode = $data === 'settings:mode:full' ? AdhkarMode::Full : AdhkarMode::Short;
            $this->settings->updateMode($user, $mode);
            $this->reply($user, __('bot.mode_saved', [
                'mode' => $mode === AdhkarMode::Full ? __('bot.mode_full') : __('bot.mode_short'),
            ])."\n\n".$this->onboarding->summary($user->fresh()));
            $this->safeAnswer($callbackId);

            return;
        }

        if ($data === 'settings:toggle_reminders') {
            $result = $this->settings->toggleReminders($user);
            $this->replyPauseResume($user->fresh(), $result);
            $this->safeAnswer($callbackId);
        }
    }

    private function handleAdditionalCallback(User $user, string $data): void
    {
        if ($data === 'add:list' || $data === 'add:back') {
            $this->sendAdditionalList($user);

            return;
        }

        if (str_starts_with($data, 'add:item:')) {
            $id = (int) substr($data, strlen('add:item:'));
            $item = AdhkarItem::query()->where('id', $id)->where('category', AdhkarCategory::Additional)->where('active', true)->first();
            if (! $item) {
                $this->reply($user, __('bot.unknown'));

                return;
            }
            $this->reply($user, $this->content->formatItem($item, $item->order), $this->keyboards->additionalItem());
        }
    }

    private function handleCompletion(User $user, int $logId, string $callbackId): void
    {
        $log = ReminderLog::query()->where('id', $logId)->where('user_id', $user->id)->first();
        if (! $log) {
            $this->safeAnswer($callbackId, __('bot.error'));

            return;
        }

        if ($log->completed_at !== null || $log->status === ReminderLogStatus::Completed) {
            $this->safeAnswer($callbackId, __('bot.already_completed'));

            return;
        }

        $log->update([
            'status' => ReminderLogStatus::Completed,
            'completed_at' => now(),
        ]);
        $this->safeAnswer($callbackId, __('bot.completed'));
    }

    private function sendAdditionalList(User $user): void
    {
        $collection = AdhkarCollection::query()->where('key', 'additional')->where('active', true)->first();
        $items = $collection
            ? $collection->items()->where('adhkar_items.active', true)->get()->map(fn (AdhkarItem $item) => [
                'id' => $item->id,
                'title' => $item->title ?: $item->key,
            ])->all()
            : [];

        $this->reply($user, __('bot.additional_intro'), $this->keyboards->additionalList($items));
    }

    private function sendCollection(User $user, ReminderType $type): void
    {
        $collection = $this->content->resolveCollection($user, $type);
        if (! $collection) {
            $this->reply($user, __('bot.content_missing'));

            return;
        }

        foreach ($this->content->buildMessages($collection) as $message) {
            $this->reply($user, $message);
        }
    }

    private function sendSettings(User $user): void
    {
        if (! $user->isOnboarded()) {
            $this->reply($user, __('bot.need_start'));

            return;
        }

        $this->reply(
            $user,
            __('bot.settings_header')."\n\n".$this->onboarding->summary($user),
            $this->keyboards->settingsMenu($user),
        );
    }

    private function handlePause(User $user): void
    {
        $this->replyPauseResume($user, $this->settings->pause($user));
    }

    private function handleResume(User $user): void
    {
        $this->replyPauseResume($user, $this->settings->resume($user));
    }

    private function replyPauseResume(User $user, string $result): void
    {
        $text = match ($result) {
            'paused' => __('bot.paused'),
            'already_paused' => __('bot.already_paused'),
            'resumed' => __('bot.resumed'),
            'already_active' => __('bot.already_active'),
            default => __('bot.need_start'),
        };

        $this->reply($user, $text, $user->isOnboarded() ? $this->keyboards->mainReplyKeyboard($user->fresh()) : null);
    }

    /**
     * @param  array{text:string,reply_markup:?array}  $response
     */
    private function sendResponse(User $user, array $response): void
    {
        $this->reply($user, $response['text'], $response['reply_markup']);
    }

    private function reply(User $user, string $text, ?array $replyMarkup = null): void
    {
        $this->telegram->sendMessage($user->chat_id, $text, $replyMarkup ?? []);
    }

    private function safeAnswer(string $callbackId, string $text = ''): void
    {
        try {
            $this->telegram->answerCallbackQuery($callbackId, $text);
        } catch (Throwable) {
            // Spinner should not break processing.
        }
    }

    private function extractCommand(string $text): ?string
    {
        if (! str_starts_with($text, '/')) {
            return null;
        }

        $command = explode(' ', strtok($text, '@') ?: $text)[0];

        return strtolower($command);
    }
}
