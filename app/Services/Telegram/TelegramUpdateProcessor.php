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
use App\Support\TimezoneCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
        $action = match ($text) {
            'أذكار اليوم', '📖 أذكار اليوم' => 'today',
            'أذكار إضافية', '🌟 أذكار إضافية' => 'additional',
            'الإعدادات', '⚙️ الإعدادات' => 'settings',
            'إيقاف التذكير', '⏸ إيقاف التذكير' => 'pause',
            'استئناف التذكير', '▶️ تفعيل التذكير', '▶️ استئناف التذكير' => 'resume',
            'مساعدة', '❓ مساعدة' => 'help',
            'شرح البوت', '🌿 شرح البوت' => 'explain',
            default => null,
        };

        if ($action === null) {
            return false;
        }

        match ($action) {
            'today' => $this->reply($user, __('bot.today_prompt'), $this->keyboards->todayMenu()),
            'additional' => $this->sendAdditionalList($user),
            'settings' => $this->sendSettings($user),
            'pause' => $this->handlePause($user),
            'resume' => $this->handleResume($user),
            'help' => $this->reply($user, __('bot.help'), $this->keyboards->mainReplyKeyboard($user)),
            'explain' => $this->sendResponse($user, $this->onboarding->explanation($user)),
        };

        return true;
    }

    private function handleAwaitingInput(User $user, string $text): void
    {
        try {
            match ($user->awaiting_input) {
                'onboarding:timezone_custom', 'settings:timezone_custom' => $this->handleCustomTimezone($user, $text),
                'onboarding:morning_time_custom', 'settings:morning_time_custom',
                'onboarding:morning_time_confirm', 'settings:morning_time_confirm' => $this->handleCustomTime($user, $text, 'morning'),
                'onboarding:evening_time_custom', 'settings:evening_time_custom',
                'onboarding:evening_time_confirm', 'settings:evening_time_confirm' => $this->handleCustomTime($user, $text, 'evening'),
                default => $this->reply($user, __('bot.unknown')),
            };
        } catch (InvalidArgumentException) {
            $fromSettings = str_starts_with((string) $user->awaiting_input, 'settings:');
            $this->reply($user, __('bot.invalid_time'), $this->navigationKeyboard($fromSettings));
        }
    }

    private function handleCustomTimezone(User $user, string $text): void
    {
        $fromSettings = str_starts_with((string) $user->awaiting_input, 'settings:');
        $multi = TimezoneCatalog::multiZone($text);
        if ($multi !== null) {
            $prefix = $fromSettings ? 'settings' : 'onboarding';
            $user->awaiting_input = "{$prefix}:timezone_region:{$multi['index']}";
            $user->save();
            $this->reply($user, __('bot.ask_region'), $this->keyboards->regionOptions($multi['index']));

            return;
        }

        $resolved = $this->onboarding->resolveTimezoneInput($text);
        if ($resolved === null) {
            $this->reply($user, __('bot.invalid_timezone'), $this->navigationKeyboard($fromSettings));

            return;
        }

        if ($fromSettings) {
            $this->settings->updateTimezone($user, $resolved['id'], $resolved['label']);
            $user->awaiting_input = null;
            $user->save();
            $this->showSettingsSaved($user, __('bot.timezone_saved', ['timezone' => $user->fresh()->timezone_label]));

            return;
        }

        $this->sendResponse($user, $this->onboarding->handleTimezone($user, $resolved['id'], $resolved['label']));
    }

    private function handleCustomTime(User $user, string $text, string $kind): void
    {
        $fromSettings = str_starts_with((string) $user->awaiting_input, 'settings:');
        try {
            $classified = ReminderScheduleCalculator::classifyTime($text, $kind);
        } catch (InvalidArgumentException) {
            $this->reply($user, __('bot.invalid_time'), $this->navigationKeyboard($fromSettings));

            return;
        }

        if ($classified['action'] === 'confirm') {
            $prefix = $fromSettings ? 'settings' : 'onboarding';
            $user->awaiting_input = "{$prefix}:{$kind}_time_confirm";
            $user->save();
            $this->reply(
                $user,
                __('bot.confirm_time_period'),
                $this->keyboards->timePeriodChoice($kind, $classified['options'], $fromSettings),
            );

            return;
        }

        $this->saveChosenTime($user, $kind, $classified['time'], $fromSettings);
    }

    private function saveChosenTime(User $user, string $kind, string $time, bool $fromSettings): void
    {
        $user->awaiting_input = null;
        $user->save();

        if ($fromSettings || $user->onboarding_state === OnboardingState::Completed) {
            if ($kind === 'morning') {
                $this->settings->updateMorningTime($user, $time);
                $fresh = $user->fresh();
                $this->showSettingsSaved($fresh, __('bot.morning_saved', ['time' => $fresh->formattedMorningTime()]));
            } else {
                $this->settings->updateEveningTime($user, $time);
                $fresh = $user->fresh();
                $this->showSettingsSaved($fresh, __('bot.evening_saved', ['time' => $fresh->formattedEveningTime()]));
            }

            return;
        }

        $response = $kind === 'morning'
            ? $this->onboarding->handleMorningTime($user, $time)
            : $this->onboarding->handleEveningTime($user, $time);
        $this->sendResponse($user, $response);
    }

    /**
     * @return array<string, mixed>
     */
    private function navigationKeyboard(bool $fromSettings): array
    {
        return $fromSettings ? $this->keyboards->backCancel() : $this->keyboards->backOnly();
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

        if ($data === 'nav:back') {
            $this->sendResponse($user, $this->onboarding->back($user->fresh()));
            $this->safeAnswer($callbackId);

            return;
        }

        if ($data === 'nav:cancel') {
            $this->sendResponse($user, $this->onboarding->cancel($user->fresh()));
            $this->safeAnswer($callbackId);

            return;
        }

        if ($data === 'today:menu') {
            $this->reply($user, __('bot.today_prompt'), $this->keyboards->todayMenu());
            $this->safeAnswer($callbackId);

            return;
        }

        if ($data === 'onb:reset') {
            $this->sendResponse($user, $this->onboarding->reset($user));
            $this->safeAnswer($callbackId);

            return;
        }

        if ($data === 'guide:explain') {
            $this->sendResponse($user, $this->onboarding->explanation($user));
            $this->safeAnswer($callbackId);

            return;
        }

        if ($data === 'onb:begin') {
            $this->showOnboardingScreen($user, $this->onboarding->beginSetup($user), $messageId);
            $this->safeAnswer($callbackId);

            return;
        }

        if (str_starts_with($data, 'onb:tzr:')) {
            $this->handleRegionCallback($user, $data, $callbackId);

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

        if (str_starts_with($data, 'sv:')) {
            $this->handleOfflineSave($user, $data, $callbackId);

            return;
        }

        if (str_starts_with($data, 'rd:')) {
            $this->handleScheduledReadingNav($user, $data, $callbackId, $messageId);

            return;
        }

        if (str_starts_with($data, 'rt:')) {
            $this->handleTodayReadingNav($user, $data, $callbackId, $messageId);

            return;
        }

        if (str_starts_with($data, 'reminder:start:')) {
            $this->handleReminderStart($user, (int) substr($data, strlen('reminder:start:')), $callbackId);

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
            $fromSettings = $user->onboarding_state === OnboardingState::Completed;
            $this->reply($user, __('bot.ask_timezone_custom'), $this->navigationKeyboard($fromSettings));
            $this->safeAnswer($callbackId);

            return;
        }

        $cities = config('adhkar.timezones', []);
        $entry = $cities[(int) $token] ?? null;
        if (! is_array($entry)) {
            $this->safeAnswer($callbackId, __('bot.invalid_timezone'));

            return;
        }

        if (! empty($entry['regions'])) {
            $prefix = $user->onboarding_state === OnboardingState::Completed ? 'settings' : 'onboarding';
            $user->awaiting_input = "{$prefix}:timezone_region:{$token}";
            $user->save();
            $this->reply($user, __('bot.ask_region'), $this->keyboards->regionOptions((int) $token));
            $this->safeAnswer($callbackId);

            return;
        }

        $timezone = $entry['id'] ?? null;
        if (! $timezone) {
            $this->safeAnswer($callbackId, __('bot.invalid_timezone'));

            return;
        }

        $label = $entry['label'] ?? $timezone;
        if ($user->onboarding_state === OnboardingState::Completed) {
            $this->settings->updateTimezone($user, $timezone, $label);
            $this->showSettingsSaved($user->fresh(), __('bot.timezone_saved', ['timezone' => $label]));
        } else {
            $this->sendResponse($user, $this->onboarding->handleTimezone($user, $timezone, $label));
        }
        $this->safeAnswer($callbackId);
    }

    private function handleRegionCallback(User $user, string $data, string $callbackId): void
    {
        $parts = explode(':', $data);
        $countryIndex = (int) ($parts[2] ?? -1);
        $regionIndex = (int) ($parts[3] ?? -1);
        $country = config('adhkar.timezones')[$countryIndex] ?? null;
        $region = $country['regions'][$regionIndex] ?? null;
        if (! is_array($region) || empty($region['id'])) {
            $this->safeAnswer($callbackId, __('bot.invalid_timezone'));

            return;
        }

        $label = ($country['label'] ?? '').' · '.$region['label'];
        if ($user->onboarding_state === OnboardingState::Completed) {
            $this->settings->updateTimezone($user, $region['id'], $label);
            $user->awaiting_input = null;
            $user->save();
            $this->showSettingsSaved($user->fresh(), __('bot.timezone_saved', ['timezone' => $label]));
        } else {
            $this->sendResponse($user, $this->onboarding->handleTimezone($user, $region['id'], $label));
        }
        $this->safeAnswer($callbackId);
    }

    private function handleTimeCallback(User $user, string $data, string $callbackId): void
    {
        $parts = explode(':', $data);
        $kind = $parts[2] ?? 'morning';
        $value = $parts[3] ?? '';

        if ($value === 'custom') {
            $fromSettings = $user->onboarding_state === OnboardingState::Completed;
            $prefix = $fromSettings ? 'settings' : 'onboarding';
            $user->awaiting_input = "{$prefix}:{$kind}_time_custom";
            $user->save();
            $prompt = $kind === 'evening' ? __('bot.ask_time_custom_evening') : __('bot.ask_time_custom_morning');
            $this->reply($user, $prompt, $this->navigationKeyboard($fromSettings));
            $this->safeAnswer($callbackId);

            return;
        }

        $time = strlen($value) >= 4
            ? substr($value, 0, 2).':'.substr($value, 2, 2)
            : $value;
        if (! ReminderScheduleCalculator::isValidTime($time)) {
            $this->safeAnswer($callbackId, __('bot.invalid_time'));

            return;
        }

        $fromSettings = $user->onboarding_state === OnboardingState::Completed;
        $this->saveChosenTime($user, $kind === 'evening' ? 'evening' : 'morning', $time, $fromSettings);
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
            $this->showTodayMenu($user, $messageId);
            $this->safeAnswer($callbackId);

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

        if ($data === 'settings:mode') {
            $this->reply($user, __('bot.ask_mode'), $this->keyboards->modeOptions('settings'));
            $this->safeAnswer($callbackId);

            return;
        }

        if ($data === 'settings:mode:short' || $data === 'settings:mode:full') {
            $mode = $data === 'settings:mode:full' ? AdhkarMode::Full : AdhkarMode::Short;
            $this->settings->updateMode($user, $mode);
            $label = $mode === AdhkarMode::Full ? __('bot.mode_full') : __('bot.mode_short');
            $this->showSettingsSaved($user->fresh(), __('bot.mode_saved', ['mode' => $label]));
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
        if ($data === 'add:list') {
            $this->sendAdditionalList($user);

            return;
        }

        if ($data === 'add:back') {
            $this->reply($user, __('bot.today_prompt'), $this->keyboards->todayMenu());

            return;
        }

        if (str_starts_with($data, 'add:group:')) {
            $this->sendAdditionalGroup($user, substr($data, strlen('add:group:')));

            return;
        }

        if (str_starts_with($data, 'add:item:')) {
            $id = (int) substr($data, strlen('add:item:'));
            $item = AdhkarItem::query()->where('id', $id)->where('category', AdhkarCategory::Additional)->where('active', true)->first();
            if (! $item) {
                $this->reply($user, __('bot.unknown'));

                return;
            }
            $this->reply($user, $this->content->formatItem($item, $item->order, detailed: true), $this->keyboards->additionalItem());
        }
    }

    private function handleReminderStart(User $user, int $logId, string $callbackId): void
    {
        $log = ReminderLog::query()->where('id', $logId)->where('user_id', $user->id)->first();
        if ($log === null) {
            $this->safeAnswer($callbackId, __('bot.error'));

            return;
        }

        if (! $this->isCurrentLocalDay($user, $log)) {
            $this->safeAnswer($callbackId);
            $this->reply($user, __('bot.reminder_expired'), $this->keyboards->todayShortcut());

            return;
        }

        $claim = $this->claimReading($log);
        if ($claim === 'started') {
            $this->safeAnswer($callbackId, __('bot.reading_already_started'));

            return;
        }

        if ($claim !== 'ok') {
            $this->safeAnswer($callbackId, $claim === 'missing_collection' ? __('bot.content_missing') : __('bot.error'));

            return;
        }

        $this->safeAnswer($callbackId);
        $log->refresh();
        $this->deliverScheduledReading($user, $log);
    }

    private function claimReading(ReminderLog $log): string
    {
        return DB::transaction(function () use ($log) {
            $locked = ReminderLog::query()->whereKey($log->id)->lockForUpdate()->first();
            if ($locked === null) {
                return 'missing';
            }

            if ($locked->reading_started_at !== null
                || filled($locked->content_snapshot_hash)
                || $locked->status === ReminderLogStatus::Completed) {
                return 'started';
            }

            if ($locked->status !== ReminderLogStatus::Sent) {
                return 'not_sent';
            }

            $collection = AdhkarCollection::query()
                ->where('key', $locked->collection_key)
                ->where('active', true)
                ->first();
            if ($collection === null) {
                return 'missing_collection';
            }

            $items = $collection->items()->where('adhkar_items.active', true)->get();
            $snapshot = $this->content->snapshotVersions($items);
            $locked->reading_started_at = now();
            $locked->content_version_map = $snapshot['content_version_map'];
            $locked->content_snapshot_hash = $snapshot['content_snapshot_hash'];
            $locked->save();

            return 'ok';
        });
    }

    private function deliverScheduledReading(User $user, ReminderLog $log): void
    {
        $collection = AdhkarCollection::query()
            ->where('key', $log->collection_key)
            ->where('active', true)
            ->first();
        if ($collection === null) {
            $this->reply($user, __('bot.content_missing'));

            return;
        }

        $phase = str_contains((string) $log->collection_key, '_full') ? 'a' : 's';
        $this->presentReading($user, [
            'scope' => 'log',
            'log' => $log,
            'baseKey' => (string) $log->collection_key,
            'phase' => $phase,
            'index' => 0,
        ], null);
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

        if (! $this->canComplete($user, $log)) {
            $this->safeAnswer($callbackId, __('bot.error'));

            return;
        }

        $log->update([
            'status' => ReminderLogStatus::Completed,
            'completed_at' => now(),
        ]);

        $text = $log->reminder_type === ReminderType::Evening
            ? __('bot.completed_evening')
            : __('bot.completed_morning');
        $this->safeAnswer($callbackId, $text);
    }

    private function canComplete(User $user, ReminderLog $log): bool
    {
        if (! $this->isCurrentLocalDay($user, $log)) {
            return false;
        }

        if (! in_array($log->reminder_type, [ReminderType::Morning, ReminderType::Evening], true)) {
            return false;
        }

        return $log->status === ReminderLogStatus::Sent
            && $log->reading_started_at !== null
            && filled($log->content_snapshot_hash);
    }

    private function isCurrentLocalDay(User $user, ReminderLog $log): bool
    {
        if (! $user->timezone || $log->scheduled_local_date === null) {
            return false;
        }

        try {
            $today = now()->timezone($user->timezone)->toDateString();
        } catch (Throwable) {
            return false;
        }

        return $log->scheduled_local_date->toDateString() === $today;
    }

    private function sendAdditionalList(User $user): void
    {
        $this->reply($user, __('bot.additional_intro'), $this->keyboards->additionalGroups());
    }

    private function sendAdditionalGroup(User $user, string $groupKey): void
    {
        $items = $this->additionalItems($groupKey);
        if ($items === null) {
            $this->reply($user, __('bot.unknown'));

            return;
        }

        $this->reply($user, __('bot.additional_intro'), $this->keyboards->additionalList($items));
    }

    /**
     * @return array<int, array{id:int,title:string}>|null
     */
    private function additionalItems(string $groupKey): ?array
    {
        $query = AdhkarItem::query()
            ->where('category', AdhkarCategory::Additional)
            ->where('active', true);

        if ($groupKey === 'all') {
            return $query->orderBy('order')->get()->map(fn (AdhkarItem $item) => [
                'id' => $item->id,
                'title' => $item->title ?: $item->key,
            ])->all();
        }

        $group = collect(config('adhkar.additional_groups', []))->firstWhere('key', $groupKey);
        if (! is_array($group)) {
            return null;
        }

        $keys = $group['items'] ?? [];
        $items = $query->whereIn('key', $keys)->get()->sortBy(
            fn (AdhkarItem $item) => array_search($item->key, $keys, true)
        );

        return $items->map(fn (AdhkarItem $item) => [
            'id' => $item->id,
            'title' => $item->title ?: $item->key,
        ])->values()->all();
    }

    private function sendCollection(User $user, ReminderType $type): void
    {
        $key = $this->content->collectionKey($user->mode ?? AdhkarMode::Short, $type);
        $this->presentReading($user, [
            'scope' => 'today',
            'period' => $type === ReminderType::Evening ? 'e' : 'm',
            'log' => null,
            'baseKey' => $key,
            'phase' => str_contains($key, '_full') ? 'a' : 's',
            'index' => 0,
        ], null);
    }

    private function handleScheduledReadingNav(User $user, string $data, string $callbackId, ?int $messageId): void
    {
        $parts = explode(':', $data);
        $phase = $parts[2] ?? '';
        if (! in_array($phase, ['s', 'f', 'a'], true)) {
            $this->safeAnswer($callbackId, __('bot.unknown'));

            return;
        }

        $log = ReminderLog::query()
            ->where('id', (int) ($parts[1] ?? 0))
            ->where('user_id', $user->id)
            ->first();
        if ($log === null || $log->reading_started_at === null) {
            $this->safeAnswer($callbackId, __('bot.error'));

            return;
        }

        $this->safeAnswer($callbackId);
        $this->presentReading($user, [
            'scope' => 'log',
            'log' => $log,
            'baseKey' => (string) $log->collection_key,
            'phase' => $phase,
            'index' => (int) ($parts[3] ?? 0),
        ], $messageId);
    }

    private function handleTodayReadingNav(User $user, string $data, string $callbackId, ?int $messageId): void
    {
        $parts = explode(':', $data);
        $period = $parts[1] ?? '';
        $phase = $parts[2] ?? '';
        if (! in_array($period, ['m', 'e'], true) || ! in_array($phase, ['s', 'f', 'a'], true)) {
            $this->safeAnswer($callbackId, __('bot.unknown'));

            return;
        }

        $type = $period === 'e' ? ReminderType::Evening : ReminderType::Morning;
        $baseKey = $phase === 'a'
            ? $this->content->collectionKey(AdhkarMode::Full, $type)
            : $this->content->collectionKey(AdhkarMode::Short, $type);

        $this->safeAnswer($callbackId);
        $this->presentReading($user, [
            'scope' => 'today',
            'period' => $period,
            'log' => null,
            'baseKey' => $baseKey,
            'phase' => $phase,
            'index' => (int) ($parts[3] ?? 0),
        ], $messageId);
    }

    /**
     * @param  array{scope:string,period?:string,log:?ReminderLog,baseKey:string,phase:string,index:int}  $session
     */
    private function presentReading(User $user, array $session, ?int $messageId): void
    {
        $items = $this->content->itemsForPhase($session['baseKey'], $session['phase']);
        $total = $items->count();
        $index = $session['index'];
        if ($total === 0 || $index < 0 || $index >= $total) {
            $this->reply($user, __('bot.content_missing'));

            return;
        }

        $headerKey = $session['phase'] === 's'
            ? $session['baseKey']
            : $this->content->fullKey($session['baseKey']);
        $isLast = $index === $total - 1;
        $text = $this->content->renderCard($headerKey, $items[$index], $index + 1, $total, $isLast);
        $markup = $this->keyboards->readingNav($this->readingActions($session, $index, $total, $isLast));

        try {
            if ($messageId !== null) {
                $this->telegram->editMessageText($user->chat_id, $messageId, $text, $markup);

                return;
            }
            $this->telegram->sendMessage($user->chat_id, $text, $markup);
        } catch (Throwable $exception) {
            if ($messageId !== null) {
                try {
                    $this->telegram->sendMessage($user->chat_id, $text, $markup);

                    return;
                } catch (Throwable $sendException) {
                    $exception = $sendException;
                }
            }
            $this->recordReadingFailure($user, $session, $exception);
        }
    }

    /**
     * @param  array{scope:string,period?:string,log:?ReminderLog,baseKey:string,phase:string,index:int}  $session
     * @return array{prev?:string,next?:string,continue?:string,done?:string,save?:string}
     */
    private function readingActions(array $session, int $index, int $total, bool $isLast): array
    {
        $actions = [
            'save' => $this->saveCallback($session),
        ];
        if ($index > 0) {
            $actions['prev'] = $this->readingCallback($session, $session['phase'], $index - 1);
        } elseif ($session['phase'] === 'f') {
            $shortCount = $this->content->itemsForPhase($session['baseKey'], 's')->count();
            if ($shortCount > 0) {
                $actions['prev'] = $this->readingCallback($session, 's', $shortCount - 1);
            }
        }

        if (! $isLast) {
            $actions['next'] = $this->readingCallback($session, $session['phase'], $index + 1);
        }

        if ($isLast && $session['phase'] === 's' && $this->content->fullOnlyItems($session['baseKey'])->isNotEmpty()) {
            $actions['continue'] = $this->readingCallback($session, 'f', 0);
        }

        if ($isLast && $session['scope'] === 'log' && $session['log'] instanceof ReminderLog) {
            $actions['done'] = 'reminder:done:'.$session['log']->id;
        }

        return $actions;
    }

    /**
     * @param  array{scope:string,period?:string,log:?ReminderLog,baseKey:string,phase:string,index:int}  $session
     */
    private function readingCallback(array $session, string $phase, int $index): string
    {
        if ($session['scope'] === 'log' && $session['log'] instanceof ReminderLog) {
            return 'rd:'.$session['log']->id.':'.$phase.':'.$index;
        }

        return 'rt:'.($session['period'] ?? 'm').':'.$phase.':'.$index;
    }

    /**
     * @param  array{scope:string,period?:string,log:?ReminderLog,phase:string}  $session
     */
    private function saveCallback(array $session): string
    {
        if ($session['scope'] === 'log' && $session['log'] instanceof ReminderLog) {
            return 'sv:l:'.$session['log']->id.':'.$session['phase'];
        }

        return 'sv:t:'.($session['period'] ?? 'm').':'.$session['phase'];
    }

    private function showTodayMenu(User $user, ?int $messageId): void
    {
        $text = __('bot.today_prompt');
        $markup = $this->keyboards->todayMenu();
        if ($messageId === null) {
            $this->reply($user, $text, $markup);

            return;
        }

        try {
            $this->telegram->editMessageText($user->chat_id, $messageId, $text, $markup);
        } catch (Throwable) {
            $this->reply($user, $text, $markup);
        }
    }

    private function handleOfflineSave(User $user, string $data, string $callbackId): void
    {
        $parts = explode(':', $data);
        $kind = $parts[1] ?? '';
        $session = null;

        if ($kind === 'l') {
            $log = ReminderLog::query()
                ->where('id', (int) ($parts[2] ?? 0))
                ->where('user_id', $user->id)
                ->first();
            $phase = $parts[3] ?? '';
            if ($log === null || $log->reading_started_at === null || ! in_array($phase, ['s', 'f', 'a'], true)) {
                $this->safeAnswer($callbackId, __('bot.error'));

                return;
            }
            $session = [
                'baseKey' => (string) $log->collection_key,
                'phase' => $phase,
            ];
        } elseif ($kind === 't') {
            $period = $parts[2] ?? '';
            $phase = $parts[3] ?? '';
            if (! in_array($period, ['m', 'e'], true) || ! in_array($phase, ['s', 'f', 'a'], true)) {
                $this->safeAnswer($callbackId, __('bot.unknown'));

                return;
            }
            $type = $period === 'e' ? ReminderType::Evening : ReminderType::Morning;
            $session = [
                'baseKey' => $phase === 'a'
                    ? $this->content->collectionKey(AdhkarMode::Full, $type)
                    : $this->content->collectionKey(AdhkarMode::Short, $type),
                'phase' => $phase,
            ];
        }

        if ($session === null) {
            $this->safeAnswer($callbackId, __('bot.unknown'));

            return;
        }

        $items = $this->content->itemsForPhase($session['baseKey'], $session['phase']);
        if ($items->isEmpty()) {
            $this->safeAnswer($callbackId, __('bot.content_missing'));

            return;
        }

        $headerKey = $session['phase'] === 's'
            ? $session['baseKey']
            : $this->content->fullKey($session['baseKey']);
        $this->safeAnswer($callbackId);

        try {
            foreach ($this->content->offlineMessages($headerKey, $items) as $message) {
                $this->telegram->sendMessage($user->chat_id, $message);
            }
        } catch (Throwable $exception) {
            Log::channel('telegram')->error('Offline reading delivery failed', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);
            $this->reply($user, __('bot.reading_failed'), $this->keyboards->todayShortcut());
        }
    }

    /**
     * @param  array{scope:string,log:?ReminderLog}  $session
     */
    private function recordReadingFailure(User $user, array $session, Throwable $exception): void
    {
        Log::channel('telegram')->error('Reading delivery failed', [
            'user_id' => $user->id,
            'error' => $exception->getMessage(),
        ]);

        if ($session['log'] instanceof ReminderLog) {
            $session['log']->error_message = Str::limit($exception->getMessage(), 1000);
            $session['log']->save();
        }

        $this->reply($user, __('bot.reading_failed'), $this->keyboards->todayShortcut());
    }

    private function showSettingsSaved(User $user, string $lead): void
    {
        $fresh = $user->fresh() ?? $user;
        $this->reply(
            $fresh,
            $lead."\n\n".$this->onboarding->summary($fresh),
            $this->keyboards->settingsMenu($fresh),
        );
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
        $user = $user->fresh() ?? $user;
        $text = match ($result) {
            'paused' => __('bot.paused'),
            'already_paused' => __('bot.already_paused'),
            'resumed' => __('bot.resumed', [
                'morning' => $user->formattedMorningTime() ?: '—',
                'evening' => $user->formattedEveningTime() ?: '—',
            ]),
            'already_active' => __('bot.already_active'),
            default => __('bot.need_start'),
        };

        $this->reply($user, $text, $user->isOnboarded() ? $this->keyboards->mainReplyKeyboard($user) : null);
    }

    /**
     * @param  array{text:string,reply_markup:?array}  $response
     */
    private function sendResponse(User $user, array $response): void
    {
        $this->reply($user, $response['text'], $response['reply_markup']);
    }

    /**
     * @param  array{text:string,reply_markup:?array}  $screen
     */
    private function showOnboardingScreen(User $user, array $screen, ?int $messageId): void
    {
        $markup = $screen['reply_markup'] ?? [];
        if ($messageId === null) {
            $this->reply($user, $screen['text'], $markup);

            return;
        }

        try {
            $this->telegram->editMessageText($user->chat_id, $messageId, $screen['text'], $markup);
        } catch (Throwable $exception) {
            if (str_contains($exception->getMessage(), 'message is not modified')) {
                return;
            }

            $this->reply($user, $screen['text'], $markup);
        }
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
