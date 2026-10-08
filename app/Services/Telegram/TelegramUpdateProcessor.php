<?php

namespace App\Services\Telegram;

use App\Enums\AdhkarCategory;
use App\Enums\AdhkarMode;
use App\Enums\OnboardingState;
use App\Enums\ReminderLogStatus;
use App\Enums\ReminderType;
use App\Exceptions\InvalidTimeException;
use App\Models\AdhkarCollection;
use App\Models\AdhkarItem;
use App\Models\ReminderLog;
use App\Models\User;
use App\Services\AdhkarContentService;
use App\Services\AdminReportService;
use App\Services\BotEventRecorder;
use App\Services\FeedbackService;
use App\Services\OnboardingService;
use App\Services\ReadingSessionRecorder;
use App\Services\ReminderScheduleCalculator;
use App\Services\UserSettingsService;
use App\Support\DayPeriod;
use App\Support\IntentMatcher;
use App\Support\NearestTimezone;
use App\Support\Telegram\KeyboardFactory;
use App\Support\Telegram\TelegramMessage;
use App\Support\Telegram\TelegramUpdate as IncomingUpdate;
use App\Support\Telegram\TelegramUser;
use App\Support\TimezoneCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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
        private readonly WirdSender $wirds,
        private readonly GroupChatService $groups,
        private readonly KeyboardFactory $keyboards,
        private readonly BotEventRecorder $events,
        private readonly FeedbackService $feedback,
        private readonly ReadingSessionRecorder $readings,
        private readonly AdminReportService $reports,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        // A user's chat_id is where their reminders go, so group updates never reach the per-user flow below.
        if ($this->groups->isGroupUpdate($payload)) {
            $this->groups->handle($payload);

            return;
        }

        if (isset($payload['my_chat_member'])) {
            $this->recordPrivateMembership($payload['my_chat_member']);

            return;
        }

        $update = IncomingUpdate::fromArray($payload);
        $from = $update->from();
        $chatId = $update->chatId();

        if ($from === null || $chatId === null) {
            return;
        }

        $user = $this->findOrCreateUser($from, $chatId);
        $user->last_interaction_at = now();
        $user->save();

        if ($update->message !== null && $this->handleFeedbackMessage($user, $update->message)) {
            return;
        }

        if ($update->callbackQuery) {
            $this->handleCallback($user, $update);

            return;
        }

        if ($update->message?->text) {
            $this->handleMessage($user, $update);

            return;
        }

        if ($update->message?->location !== null && str_ends_with((string) $user->awaiting_input, ':location')) {
            $this->handleSharedLocation($user, $update->message->location['latitude'], $update->message->location['longitude']);

            return;
        }

        if ($update->message !== null) {
            $this->handleNonTextMessage($user, $update->message->kind);
        }
    }

    /**
     * Telegram reports here when a user blocks or unblocks the bot in their private chat.
     *
     * @param  array<string, mixed>  $membership
     */
    private function recordPrivateMembership(array $membership): void
    {
        $status = data_get($membership, 'new_chat_member.status');
        $user = User::query()->where('telegram_id', (int) data_get($membership, 'from.id'))->first();
        if ($user === null || ! in_array($status, ['kicked', 'member'], true)) {
            return;
        }

        $this->events->record($user, $status === 'kicked' ? 'bot_blocked' : 'bot_unblocked');
    }

    /**
     * True when the message was feedback from a user, or the owner's reply to one.
     * Commands and keyboard buttons close an open feedback prompt and work as usual.
     */
    private function handleFeedbackMessage(User $user, TelegramMessage $message): bool
    {
        if ($this->feedback->isAdminChat($message->chatId) && $this->feedback->relayAdminReply($message)) {
            return true;
        }

        if (! $this->feedback->isOpen($user)) {
            return false;
        }

        $text = trim((string) $message->text);
        if ($text !== '' && ($this->extractCommand($text) !== null || $this->keyboardAction($text) !== null)) {
            $this->feedback->close($user);

            return false;
        }

        $result = $this->feedback->receive($user, $message);
        $this->reply(
            $user,
            __($result === 'sent' ? 'bot.feedback_thanks' : 'bot.feedback_limited'),
            $user->isOnboarded() ? $this->keyboards->mainReplyKeyboard($user) : null,
        );

        return true;
    }

    private function startFeedback(User $user, ?int $messageId): void
    {
        $this->feedback->open($user);
        $this->show($user, __('bot.feedback_prompt'), $this->keyboards->feedbackPrompt(), $messageId);
    }

    private function askForLocation(User $user, string $callbackId): void
    {
        $prefix = $user->onboarding_state === OnboardingState::Completed ? 'settings' : 'onboarding';
        $user->awaiting_input = "{$prefix}:location";
        $user->save();
        $this->safeAnswer($callbackId);
        $this->reply($user, __('bot.location_ask'), $this->keyboards->locationRequest());
    }

    /**
     * Only the matched timezone is kept, briefly, until the user confirms; the coordinates are never stored.
     */
    private function handleSharedLocation(User $user, float $latitude, float $longitude): void
    {
        $timezone = NearestTimezone::find($latitude, $longitude);
        $this->events->record($user, 'location_shared', ['found' => $timezone !== null]);
        if ($timezone === null) {
            $this->showTimezoneChoice($user, __('bot.location_failed'), null);

            return;
        }

        Cache::put('location_tz:'.$user->id, $timezone, now()->addMinutes(30));
        $this->reply($user, __('bot.location_confirm', [
            'time' => ReminderScheduleCalculator::format12Hour(now($timezone)->format('H:i')),
            'place' => e($this->timezoneLabel($timezone)),
        ]), $this->keyboards->locationConfirm());
    }

    private function handleLocationAnswer(User $user, string $data, string $callbackId, ?int $messageId): void
    {
        $this->safeAnswer($callbackId);
        $timezone = Cache::pull('location_tz:'.$user->id);

        if ($data !== 'loc:yes') {
            $this->showTimezoneChoice($user, __('bot.ask_timezone'), $messageId);

            return;
        }

        if (! is_string($timezone) || ! str_ends_with((string) $user->awaiting_input, ':location')) {
            $this->showTimezoneChoice($user, __('bot.location_expired'), $messageId);

            return;
        }

        $label = $this->timezoneLabel($timezone);
        $user->awaiting_input = null;
        $user->save();

        if ($user->onboarding_state === OnboardingState::Completed) {
            $this->settings->updateTimezone($user, $timezone, $label);
            $this->showSettingsSaved($user->fresh(), __('bot.timezone_saved', ['timezone' => e($label)]), $messageId);
            $this->reply($user, __('bot.keyboard_restored'), $this->keyboards->mainReplyKeyboard($user->fresh() ?? $user));

            return;
        }

        $this->sendOnboardingStep($user, $this->onboarding->handleTimezone($user, $timezone, $label), $messageId, 'timezone_location');
    }

    private function showTimezoneChoice(User $user, string $text, ?int $messageId): void
    {
        $fromSettings = $user->onboarding_state === OnboardingState::Completed;
        $user->awaiting_input = null;
        $user->save();
        $this->show($user, $text, $this->keyboards->timezoneOptions($fromSettings), $messageId);
    }

    private function timezoneLabel(string $timezone): string
    {
        return TimezoneCatalog::labelForTimezone($timezone)
            ?? str_replace('_', ' ', substr($timezone, (int) strrpos($timezone, '/') + 1));
    }

    private function handleNonTextMessage(User $user, string $kind): void
    {
        $key = in_array($kind, ['voice', 'audio', 'photo', 'sticker', 'video', 'video_note', 'animation', 'document', 'contact', 'location'], true)
            ? $kind
            : 'other';
        $text = __('bot.media.'.$key);
        $this->events->record($user, 'media', ['kind' => $key]);

        if ($user->onboarding_state !== OnboardingState::Completed) {
            $screen = $this->onboarding->start($user);
            $this->reply($user, $text."\n\n".$screen['text'], $screen['reply_markup']);

            return;
        }

        $this->reply($user, $text."\n\n".__('bot.media_hint'), $this->keyboards->mainReplyKeyboard($user));
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

        if ($command === '/stats' && $this->feedback->isAdminChat($user->chat_id)) {
            $days = (int) (explode(' ', $text)[1] ?? 7);
            $this->reply($user, $this->reports->build(min(90, max(1, $days))));

            return;
        }

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

        $this->handleUnknownText($user, $text);
    }

    private function handleCommand(User $user, string $command): void
    {
        match ($command) {
            '/start' => $this->sendStart($user),
            '/settings' => $this->sendSettings($user),
            '/help' => $this->showHelpMenu($user, null),
            '/pause' => $this->handlePause($user),
            '/resume' => $this->handleResume($user),
            '/today' => $this->showTodayMenu($user, null),
            '/feedback' => $this->startFeedback($user, null),
            default => $this->reply($user, __('bot.unknown')),
        };
    }

    /**
     * A returning user only sees the inline menu on /start, so the bottom buttons would stay
     * missing (e.g. in a new chat) until something else sends them.
     */
    private function sendStart(User $user): void
    {
        $this->sendResponse($user, $this->onboarding->start($user));

        if ($user->isOnboarded()) {
            $this->reply($user, __('bot.keyboard_hint'), $this->keyboards->mainReplyKeyboard($user));
        }
    }

    private function handleReplyKeyboard(User $user, string $text): bool
    {
        $action = $this->keyboardAction($text);

        if ($action === null) {
            return false;
        }

        match ($action) {
            'today' => $this->showTodayMenu($user, null),
            'morning' => $this->openPeriod($user, ReminderType::Morning, null),
            'evening' => $this->openPeriod($user, ReminderType::Evening, null),
            'additional' => $this->sendAdditionalList($user, null),
            'bedtime' => $this->startManualReading($user, 'b'),
            'waking' => $this->startManualReading($user, 'w'),
            'prayer' => $this->startManualReading($user, 'p'),
            'settings' => $this->sendSettings($user),
            'pause' => $this->askPauseConfirmation($user),
            'resume' => $this->handleResume($user),
            'help' => $this->showHelpMenu($user, null),
            'explain' => $this->showHelpTopic($user, 'idea', null),
            'feedback' => $this->startFeedback($user, null),
        };

        return true;
    }

    private function keyboardAction(string $text): ?string
    {
        return match ($text) {
            'أذكار اليوم', '📖 أذكار اليوم' => 'today',
            'أذكار الصباح', '🌅 أذكار الصباح' => 'morning',
            'أذكار المساء', '🌙 أذكار المساء' => 'evening',
            'أذكار إضافية', '🌟 أذكار إضافية' => 'additional',
            'أذكار النوم', '🌙 أذكار النوم', '🛌 أذكار النوم' => 'bedtime',
            'أذكار الاستيقاظ', '🌅 أذكار الاستيقاظ', '☀️ أذكار الاستيقاظ' => 'waking',
            'أذكار الصلاة', '🕌 أذكار الصلاة', 'أذكار بعد الصلاة', '🕌 أذكار بعد الصلاة' => 'prayer',
            'الإعدادات', '⚙️ الإعدادات' => 'settings',
            'إيقاف التذكير', '⏸ إيقاف التذكير' => 'pause',
            'استئناف التذكير', '▶️ تفعيل التذكير', '▶️ استئناف التذكير' => 'resume',
            'مساعدة', '❓ مساعدة', 'المساعدة والشرح', '❓ المساعدة والشرح' => 'help',
            'شرح البوت', '🌿 شرح البوت' => 'explain',
            'أرسل ملاحظة', '💬 أرسل ملاحظة', 'أرسل ملاحظة أو اقتراحًا', '💬 أرسل ملاحظة أو اقتراحًا' => 'feedback',
            default => null,
        };
    }

    private function handleUnknownText(User $user, string $text): void
    {
        $input = $this->quote($text);

        if ($user->onboarding_state !== OnboardingState::Completed) {
            $this->events->unknownText($user, $text);
            $screen = $this->onboarding->start($user);
            $this->reply(
                $user,
                __('bot.unknown_onboarding', ['input' => $input])."\n\n".$screen['text'],
                $screen['reply_markup'],
            );

            return;
        }

        $intent = IntentMatcher::match($text);
        if ($intent !== null) {
            $this->events->record($user, 'intent', ['intent' => $intent]);
            $this->handleIntent($user, $intent);

            return;
        }

        $this->events->unknownText($user, $text);
        $this->reply($user, __('bot.unknown_text', ['input' => $input]), $this->keyboards->mainReplyKeyboard($user));
    }

    /**
     * Anything that changes a setting only opens its confirmation; the user still taps to apply it.
     */
    private function handleIntent(User $user, string $intent): void
    {
        match ($intent) {
            'morning' => $this->openPeriod($user, ReminderType::Morning, null),
            'evening' => $this->openPeriod($user, ReminderType::Evening, null),
            'bedtime' => $this->startManualReading($user, 'b'),
            'waking' => $this->startManualReading($user, 'w'),
            'time', 'settings' => $this->sendSettings($user),
            'help' => $this->showHelpMenu($user, null),
            'pause' => $this->askPauseConfirmation($user),
            'resume' => $user->reminders_enabled
                ? $this->reply($user, __('bot.already_active'), $this->keyboards->mainReplyKeyboard($user))
                : $this->reply($user, __('bot.resume_confirm'), $this->keyboards->resumeConfirm()),
            'thanks' => $this->reply($user, __('bot.thanks_reply'), $this->keyboards->mainReplyKeyboard($user)),
            'salam' => $this->reply($user, __('bot.salam_reply'), $this->keyboards->mainReplyKeyboard($user)),
            default => $this->reply($user, __('bot.greeting_reply'), $this->keyboards->mainReplyKeyboard($user)),
        };
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
                'onboarding:location', 'settings:location' => $this->showTimezoneChoice($user, __('bot.ask_timezone'), null),
                default => $this->handleUnknownText($user, $text),
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
            $digits = preg_match('/\d/u', ReminderScheduleCalculator::toAsciiDigits($text)) === 1;
            $this->reply(
                $user,
                __($digits ? 'bot.timezone_digits' : 'bot.timezone_unknown', [
                    'input' => $digits ? $this->quoteLtr($text) : $this->quote($text),
                ]),
                $this->keyboards->timezoneRetry($fromSettings),
            );

            return;
        }

        if ($fromSettings) {
            $this->settings->updateTimezone($user, $resolved['id'], $resolved['label']);
            $user->awaiting_input = null;
            $user->save();
            $this->showSettingsSaved($user, __('bot.timezone_saved', ['timezone' => $user->fresh()->timezone_label]), null);

            return;
        }

        $this->sendOnboardingStep($user, $this->onboarding->handleTimezone($user, $resolved['id'], $resolved['label']), null, 'timezone');
    }

    private function handleCustomTime(User $user, string $text, string $kind): void
    {
        $fromSettings = str_starts_with((string) $user->awaiting_input, 'settings:');
        try {
            $classified = ReminderScheduleCalculator::classifyTime($text, $kind);
        } catch (InvalidTimeException $exception) {
            $this->replyTimeError($user, $text, $kind, $fromSettings, $exception);

            return;
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

        $this->saveChosenTime($user, $kind, $classified['time'], $fromSettings, null);
    }

    /**
     * Explains what is wrong with the typed time and, when the digits make sense, offers the likely time as a button.
     */
    private function replyTimeError(User $user, string $text, string $kind, bool $fromSettings, InvalidTimeException $exception): void
    {
        $this->events->record($user, 'time_error', ['reason' => $exception->reason, 'kind' => $kind]);
        $message = __('bot.'.$exception->reason, [
            'input' => $exception->reason === InvalidTimeException::LETTERS ? $this->quote($text) : $this->quoteLtr($text),
            'example' => $kind === 'evening' ? '6:30 مساءً' : '5:30 صباحًا',
        ]);
        $markup = $this->navigationKeyboard($fromSettings);

        $options = $exception->suggestion !== null ? $this->suggestionOptions($exception->suggestion, $kind) : [];
        if ($options !== []) {
            $message .= "\n\n".__('bot.time_suggestion_hint');
            $markup = $this->keyboards->timePeriodChoice($kind, $options, $fromSettings);
        }

        $this->reply($user, $message, $markup);
    }

    /**
     * @return array<int, array{label:string,hm:string}>
     */
    private function suggestionOptions(string $suggestion, string $kind): array
    {
        try {
            $classified = ReminderScheduleCalculator::classifyTime($suggestion, $kind);
        } catch (InvalidArgumentException) {
            return [];
        }

        if ($classified['action'] === 'confirm') {
            return $classified['options'];
        }

        $hm = substr($classified['time'], 0, 5);

        return [[
            'label' => __('bot.time_suggestion_button', ['time' => ReminderScheduleCalculator::format12Hour($classified['time'])]),
            'hm' => str_replace(':', '', $hm),
        ]];
    }

    private function saveChosenTime(User $user, string $kind, string $time, bool $fromSettings, ?int $messageId): void
    {
        $user->awaiting_input = null;
        $user->save();

        if ($fromSettings || $user->onboarding_state === OnboardingState::Completed) {
            if ($kind === 'morning') {
                $this->settings->updateMorningTime($user, $time);
                $fresh = $user->fresh();
                $this->showSettingsSaved($fresh, __('bot.morning_saved', ['time' => $fresh->formattedMorningTime()]), $messageId);
            } else {
                $this->settings->updateEveningTime($user, $time);
                $fresh = $user->fresh();
                $this->showSettingsSaved($fresh, __('bot.evening_saved', ['time' => $fresh->formattedEveningTime()]), $messageId);
            }

            return;
        }

        $response = $kind === 'morning'
            ? $this->onboarding->handleMorningTime($user, $time)
            : $this->onboarding->handleEveningTime($user, $time);
        $this->sendOnboardingStep($user, $response, $messageId, $kind);
    }

    /**
     * @param  array{text:string,reply_markup:?array}  $response
     */
    private function sendOnboardingStep(User $user, array $response, ?int $messageId, string $step): void
    {
        $this->events->record($user, 'onboarding_step', ['step' => $step]);
        if ($user->fresh()?->onboarding_state === OnboardingState::Completed) {
            $this->events->record($user, 'onboarding_completed');
        }

        $this->sendResponse($user, $response, $messageId);
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

        $window = (int) config('telegram.double_press_seconds', 2);
        $pressKey = 'telegram:press:'.$user->id.':'.sha1($data.'|'.($callback->message?->messageId ?? 0));
        if ($window > 0 && ! Cache::add($pressKey, true, $window)) {
            $this->safeAnswer($callback->id);

            return;
        }

        try {
            $this->routeCallback($user, $data, $callback->id, $callback->message?->messageId);
        } catch (Throwable $exception) {
            Log::channel('telegram')->warning('Callback handling failed', [
                'data' => $data,
                'error' => TelegramClient::redact($exception->getMessage()),
            ]);
            $this->safeAnswer($callback->id, __('bot.error'));
        }
    }

    private function routeCallback(User $user, string $data, string $callbackId, ?int $messageId): void
    {
        if ($data === 'feedback:start') {
            $this->safeAnswer($callbackId);
            $this->startFeedback($user, $messageId);

            return;
        }

        if ($this->feedback->isOpen($user)) {
            $this->feedback->close($user);
            if ($data === 'feedback:cancel') {
                $this->safeAnswer($callbackId);
                $this->show($user, __('bot.feedback_cancelled'), $this->keyboards->helpMenu($user->isOnboarded()), $messageId);

                return;
            }
        }

        if ($data === 'feedback:cancel') {
            $this->safeAnswer($callbackId);
            $this->showHelpMenu($user, $messageId);

            return;
        }

        if ($data === 'noop') {
            $this->safeAnswer($callbackId);

            return;
        }

        if ($data === 'nav:back') {
            $this->safeAnswer($callbackId);
            $this->sendResponse($user, $this->onboarding->back($user->fresh()), $messageId);

            return;
        }

        if ($data === 'nav:home') {
            $this->safeAnswer($callbackId);
            $this->sendResponse($user, $this->onboarding->start($user->fresh()), $messageId);

            return;
        }

        if ($data === 'nav:cancel') {
            $this->safeAnswer($callbackId);
            $this->sendResponse($user, $this->onboarding->cancel($user->fresh()), $messageId);

            return;
        }

        if ($data === 'today:menu') {
            $this->safeAnswer($callbackId);
            $this->showTodayMenu($user, $messageId);

            return;
        }

        if ($data === 'onb:reset') {
            $this->safeAnswer($callbackId);
            if ($user->onboarding_state === OnboardingState::Completed) {
                $this->show($user, __('bot.reset_confirm'), $this->keyboards->resetConfirm(), $messageId);
            } else {
                $this->sendResponse($user, $this->onboarding->start($user), $messageId);
            }

            return;
        }

        if ($data === 'onb:reset:yes') {
            $this->safeAnswer($callbackId);
            $this->sendResponse($user, $this->onboarding->reset($user), $messageId);

            return;
        }

        if ($data === 'onb:reset:no') {
            $this->safeAnswer($callbackId);
            $this->sendResponse($user, $this->onboarding->start($user), $messageId);

            return;
        }

        if ($data === 'guide:explain') {
            $this->safeAnswer($callbackId);
            $this->showHelpTopic($user, 'idea', $messageId);

            return;
        }

        if ($data === 'help:menu') {
            $this->safeAnswer($callbackId);
            $this->showHelpMenu($user, $messageId);

            return;
        }

        if (str_starts_with($data, 'help:topic:')) {
            $this->safeAnswer($callbackId);
            $this->showHelpTopic($user, substr($data, strlen('help:topic:')), $messageId);

            return;
        }

        if ($data === 'onb:begin') {
            $this->safeAnswer($callbackId);
            if ($user->onboarding_state === OnboardingState::New) {
                $this->events->record($user, 'onboarding_started');
            }
            $this->sendResponse($user, $this->onboarding->beginSetup($user), $messageId);

            return;
        }

        if ($data === 'onb:quick:yes') {
            $this->safeAnswer($callbackId);
            $this->sendOnboardingStep($user, $this->onboarding->acceptSuggestedTimes($user), $messageId, 'quick_accepted');

            return;
        }

        if ($data === 'onb:quick:custom') {
            $this->safeAnswer($callbackId);
            $this->sendOnboardingStep($user, $this->onboarding->chooseOwnTimes($user), $messageId, 'quick_custom');

            return;
        }

        if ($data === 'onb:loc') {
            $this->askForLocation($user, $callbackId);

            return;
        }

        if ($data === 'loc:yes' || $data === 'loc:no') {
            $this->handleLocationAnswer($user, $data, $callbackId, $messageId);

            return;
        }

        if (str_starts_with($data, 'onb:tzr:')) {
            $this->handleRegionCallback($user, $data, $callbackId, $messageId);

            return;
        }

        if (str_starts_with($data, 'onb:tz:')) {
            $this->handleTimezoneCallback($user, $data, $callbackId, $messageId);

            return;
        }

        if (str_starts_with($data, 'onb:time:')) {
            $this->handleTimeCallback($user, $data, $callbackId, $messageId);

            return;
        }

        // Buttons from the removed mode step on old messages.
        if (str_starts_with($data, 'onb:mode:')) {
            $this->safeAnswer($callbackId);
            $this->sendResponse($user, $this->onboarding->finish($user), $messageId);

            return;
        }

        if (str_starts_with($data, 'settings:')) {
            $this->handleSettingsCallback($user, $data, $callbackId, $messageId);

            return;
        }

        if ($data === 'today:morning' || $data === 'today:evening') {
            $this->safeAnswer($callbackId);
            $this->openPeriod($user, $data === 'today:morning' ? ReminderType::Morning : ReminderType::Evening, $messageId);

            return;
        }

        if ($data === 'today:bedtime') {
            $this->safeAnswer($callbackId);
            $this->startManualReading($user, 'b', $messageId);

            return;
        }

        if (str_starts_with($data, 'md:')) {
            $parts = explode(':', $data);
            $period = $parts[1] ?? '';
            $phase = $parts[2] ?? '';
            if (! in_array($period, ['m', 'e'], true) || ! in_array($phase, ['s', 'a'], true)) {
                $this->replyOldButton($user, $callbackId);

                return;
            }

            $this->safeAnswer($callbackId);
            $mode = $phase === 'a' ? AdhkarMode::Full : AdhkarMode::Short;
            $this->rememberMode($user, $mode);
            $this->startPeriodReading($user, $period, $mode, $messageId);

            return;
        }

        if (str_starts_with($data, 'rsw:')) {
            $this->handleLogSwitch($user, $data, $callbackId, $messageId);

            return;
        }

        if (str_starts_with($data, 'add:')) {
            $this->handleAdditionalCallback($user, $data, $callbackId, $messageId);

            return;
        }

        if (str_starts_with($data, 'sv:') || str_starts_with($data, 'sp:')) {
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
            $parts = explode(':', substr($data, strlen('reminder:start:')));
            $phase = in_array($parts[1] ?? null, ['s', 'a'], true) ? $parts[1] : null;
            $this->handleReminderStart($user, (int) $parts[0], $phase, $callbackId, $messageId);

            return;
        }

        if (str_starts_with($data, 'reminder:done:')) {
            $this->handleCompletion($user, (int) substr($data, strlen('reminder:done:')), $callbackId);

            return;
        }

        $this->replyOldButton($user, $callbackId);
    }

    private function replyOldButton(User $user, string $callbackId): void
    {
        $this->safeAnswer($callbackId);
        $this->events->record($user, 'old_button');
        $this->reply(
            $user,
            __('bot.old_button'),
            $user->isOnboarded() ? $this->keyboards->mainReplyKeyboard($user) : null,
        );
    }

    private function handleTimezoneCallback(User $user, string $data, string $callbackId, ?int $messageId): void
    {
        $token = substr($data, strlen('onb:tz:'));
        $fromSettings = $user->onboarding_state === OnboardingState::Completed;
        $prefix = $fromSettings ? 'settings' : 'onboarding';

        if ($token === 'custom') {
            $user->awaiting_input = "{$prefix}:timezone_custom";
            $user->save();
            $this->safeAnswer($callbackId);
            $this->show($user, __('bot.ask_timezone_custom'), $this->navigationKeyboard($fromSettings), $messageId);

            return;
        }

        $cities = config('adhkar.timezones', []);
        $entry = $cities[(int) $token] ?? null;
        if (! is_array($entry)) {
            $this->safeAnswer($callbackId, __('bot.invalid_timezone'));

            return;
        }

        if (! empty($entry['regions'])) {
            $user->awaiting_input = "{$prefix}:timezone_region:{$token}";
            $user->save();
            $this->safeAnswer($callbackId);
            $this->show($user, __('bot.ask_region'), $this->keyboards->regionOptions((int) $token), $messageId);

            return;
        }

        $timezone = $entry['id'] ?? null;
        if (! $timezone) {
            $this->safeAnswer($callbackId, __('bot.invalid_timezone'));

            return;
        }

        $this->safeAnswer($callbackId);
        $label = $entry['label'] ?? $timezone;
        if ($fromSettings) {
            $this->settings->updateTimezone($user, $timezone, $label);
            $this->showSettingsSaved($user->fresh(), __('bot.timezone_saved', ['timezone' => $label]), $messageId);
        } else {
            $this->sendOnboardingStep($user, $this->onboarding->handleTimezone($user, $timezone, $label), $messageId, 'timezone');
        }
    }

    private function handleRegionCallback(User $user, string $data, string $callbackId, ?int $messageId): void
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

        $this->safeAnswer($callbackId);
        $label = ($country['label'] ?? '').' · '.$region['label'];
        if ($user->onboarding_state === OnboardingState::Completed) {
            $this->settings->updateTimezone($user, $region['id'], $label);
            $user->awaiting_input = null;
            $user->save();
            $this->showSettingsSaved($user->fresh(), __('bot.timezone_saved', ['timezone' => $label]), $messageId);
        } else {
            $this->sendOnboardingStep($user, $this->onboarding->handleTimezone($user, $region['id'], $label), $messageId, 'timezone');
        }
    }

    private function handleTimeCallback(User $user, string $data, string $callbackId, ?int $messageId): void
    {
        $parts = explode(':', $data);
        $kind = ($parts[2] ?? 'morning') === 'evening' ? 'evening' : 'morning';
        $value = $parts[3] ?? '';
        $fromSettings = $user->onboarding_state === OnboardingState::Completed;

        if ($value === 'custom') {
            $prefix = $fromSettings ? 'settings' : 'onboarding';
            $user->awaiting_input = "{$prefix}:{$kind}_time_custom";
            $user->save();
            $prompt = $kind === 'evening' ? __('bot.ask_time_custom_evening') : __('bot.ask_time_custom_morning');
            $this->safeAnswer($callbackId);
            $this->show($user, $prompt, $this->navigationKeyboard($fromSettings), $messageId);

            return;
        }

        $time = strlen($value) >= 4
            ? substr($value, 0, 2).':'.substr($value, 2, 2)
            : $value;
        if (! ReminderScheduleCalculator::isValidTime($time)) {
            $this->safeAnswer($callbackId, __('bot.invalid_time'));

            return;
        }

        $this->safeAnswer($callbackId);
        $this->saveChosenTime($user, $kind, $time, $fromSettings, $messageId);
    }

    private function handleSettingsCallback(User $user, string $data, string $callbackId, ?int $messageId): void
    {
        $this->safeAnswer($callbackId);

        if ($data === 'settings:close') {
            $this->showTodayMenu($user, $messageId);

            return;
        }

        if ($data === 'settings:timezone') {
            $this->show($user, __('bot.ask_timezone'), $this->keyboards->timezoneOptions(), $messageId);

            return;
        }

        if ($data === 'settings:morning_time') {
            $this->show($user, __('bot.ask_morning_time'), $this->keyboards->timePresets('morning'), $messageId);

            return;
        }

        if ($data === 'settings:evening_time') {
            $this->show($user, __('bot.ask_evening_time'), $this->keyboards->timePresets('evening'), $messageId);

            return;
        }

        if ($data === 'settings:pause_ask'
            || ($data === 'settings:toggle_reminders' && $user->reminders_enabled)) {
            $this->askPauseConfirmation($user, $messageId);

            return;
        }

        if ($data === 'settings:pause_yes') {
            $this->handlePause($user, $messageId);

            return;
        }

        if ($data === 'settings:resume' || $data === 'settings:toggle_reminders') {
            $this->handleResume($user, $messageId);

            return;
        }

        if ($data === 'settings:follow_up') {
            $user->follow_up_enabled = ! $user->follow_up_enabled;
            $user->save();
            $this->events->record($user, 'follow_up_setting', ['enabled' => $user->follow_up_enabled]);
            $this->showSettingsSaved($user, __($user->follow_up_enabled ? 'bot.follow_up_on' : 'bot.follow_up_off'), $messageId);

            return;
        }

        if ($data === 'settings:follow_up_off') {
            $user->follow_up_enabled = false;
            $user->save();
            $this->events->record($user, 'follow_up_setting', ['enabled' => false, 'from' => 'message']);
            $this->show($user, __('bot.follow_up_off'), null, $messageId);

            return;
        }

        if ($data === 'settings:style') {
            $current = __($user->sendsReadingWithReminder() ? 'bot.style_direct' : 'bot.style_nudge');
            $this->show($user, __('bot.style_screen', ['current' => $current]), $this->keyboards->reminderStyle($user), $messageId);

            return;
        }

        if ($data === 'settings:style:nudge' || $data === 'settings:style:direct') {
            $style = substr($data, strlen('settings:style:'));
            if ($user->reminder_style !== $style) {
                $user->reminder_style = $style;
                $user->save();
                $this->events->record($user, 'reminder_style', ['style' => $style]);
            }
            $this->showSettingsSaved($user, __('bot.reminder_style_'.$style), $messageId);

            return;
        }

        // settings:open, and the removed settings:mode buttons on old messages.
        $this->sendSettings($user, $messageId);
    }

    private function handleAdditionalCallback(User $user, string $data, string $callbackId, ?int $messageId): void
    {
        // add:back and add:group:* come from the older grouped menu; they all lead to the one list now.
        if ($data === 'add:list' || $data === 'add:back' || str_starts_with($data, 'add:group:')) {
            $this->safeAnswer($callbackId);
            $this->sendAdditionalList($user, $messageId);

            return;
        }

        if (str_starts_with($data, 'add:item:')) {
            $id = (int) explode(':', substr($data, strlen('add:item:')))[0];
            $items = $this->additionalItems();
            $position = $items->search(fn (AdhkarItem $item) => $item->id === $id);
            if ($position === false) {
                $this->safeAnswer($callbackId, __('bot.item_not_found'));

                return;
            }

            $this->safeAnswer($callbackId);
            $this->events->record($user, 'additional_item', ['item' => $items[$position]->title ?: $items[$position]->key]);
            $this->show(
                $user,
                $this->content->formatItem($items[$position], $position + 1),
                $this->keyboards->additionalItem(),
                $messageId,
            );

            return;
        }

        $this->replyOldButton($user, $callbackId);
    }

    private function handleReminderStart(User $user, int $logId, ?string $phase, string $callbackId, ?int $messageId): void
    {
        $log = ReminderLog::query()->where('id', $logId)->where('user_id', $user->id)->first();
        if ($log === null) {
            $this->safeAnswer($callbackId, __('bot.reminder_not_found'));

            return;
        }

        if (! $this->isCurrentLocalDay($user, $log)) {
            $this->safeAnswer($callbackId);
            $this->reply($user, __('bot.reminder_expired'), $this->keyboards->todayShortcut());

            return;
        }

        $claim = $this->claimReading($log, $phase);
        if ($claim === 'started') {
            $this->safeAnswer($callbackId, __('bot.reading_already_started'));

            return;
        }

        if ($claim !== 'ok') {
            $this->safeAnswer($callbackId, match ($claim) {
                'missing_collection' => __('bot.content_missing'),
                'missing' => __('bot.reminder_not_found'),
                default => __('bot.error'),
            });

            return;
        }

        $this->safeAnswer($callbackId);
        if ($phase !== null) {
            $this->rememberMode($user, $phase === 'a' ? AdhkarMode::Full : AdhkarMode::Short);
        }
        $this->events->record($user, 'reminder_opened', [
            'type' => $log->reminder_type?->value,
            'collection' => $log->fresh()?->collection_key,
        ]);
        $log->refresh();
        $this->deliverScheduledReading($user, $log, $messageId);
    }

    /**
     * @param  string|null  $phase  s or a from the reminder buttons; null keeps the key stored when the reminder was claimed
     */
    private function claimReading(ReminderLog $log, ?string $phase): string
    {
        return DB::transaction(function () use ($log, $phase) {
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

            if ($phase !== null) {
                $mode = $phase === 'a' ? AdhkarMode::Full : AdhkarMode::Short;
                $locked->collection_key = $this->content->collectionKey($mode, $locked->reminder_type);
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

    private function deliverScheduledReading(User $user, ReminderLog $log, ?int $messageId): void
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
            'opened' => true,
        ], $messageId);
    }

    private function handleCompletion(User $user, int $logId, string $callbackId): void
    {
        $log = ReminderLog::query()->where('id', $logId)->where('user_id', $user->id)->first();
        if (! $log) {
            $this->safeAnswer($callbackId, __('bot.reminder_not_found'));

            return;
        }

        if ($log->completed_at !== null || $log->status === ReminderLogStatus::Completed) {
            $this->safeAnswer($callbackId, __('bot.already_completed'));

            return;
        }

        $problem = $this->completionProblem($user, $log);
        if ($problem !== null) {
            $this->safeAnswer($callbackId, $problem);

            return;
        }

        $log->update([
            'status' => ReminderLogStatus::Completed,
            'completed_at' => now(),
        ]);
        $this->events->record($user, 'reading_completed', [
            'scope' => 'reminder',
            'collection' => $log->collection_key,
        ]);

        $text = $log->reminder_type === ReminderType::Evening
            ? __('bot.completed_evening')
            : __('bot.completed_morning');
        $this->safeAnswer($callbackId, $text);
    }

    /**
     * Null when the reminder can be marked done, otherwise the message that explains why not.
     */
    private function completionProblem(User $user, ReminderLog $log): ?string
    {
        if (! $this->isCurrentLocalDay($user, $log)) {
            return __('bot.reminder_expired_short');
        }

        if (! in_array($log->reminder_type, [ReminderType::Morning, ReminderType::Evening], true)) {
            return __('bot.error');
        }

        if ($log->reading_started_at === null || ! filled($log->content_snapshot_hash)) {
            return __('bot.start_reading_first');
        }

        return $log->status === ReminderLogStatus::Sent ? null : __('bot.error');
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

    private function sendAdditionalList(User $user, ?int $messageId): void
    {
        $list = $this->additionalItems()->map(fn (AdhkarItem $item) => [
            'id' => $item->id,
            'title' => $item->title ?: $item->key,
        ]);

        $this->show($user, __('bot.additional_intro'), $this->keyboards->additionalList($list), $messageId);
    }

    /**
     * @return Collection<int, AdhkarItem>
     */
    private function additionalItems(): Collection
    {
        return AdhkarItem::query()
            ->where('category', AdhkarCategory::Additional)
            ->where('active', true)
            ->orderBy('order')
            ->get()
            ->values();
    }

    private function handleScheduledReadingNav(User $user, string $data, string $callbackId, ?int $messageId): void
    {
        $parts = explode(':', $data);
        $phase = $parts[2] ?? '';
        if (! in_array($phase, ['s', 'f', 'a'], true)) {
            $this->replyOldButton($user, $callbackId);

            return;
        }

        $log = ReminderLog::query()
            ->where('id', (int) ($parts[1] ?? 0))
            ->where('user_id', $user->id)
            ->first();
        if ($log === null) {
            $this->safeAnswer($callbackId, __('bot.reminder_not_found'));

            return;
        }
        if ($log->reading_started_at === null) {
            $this->safeAnswer($callbackId, __('bot.start_reading_first'));

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
        $baseKey = $this->content->manualBaseKey($period, $phase);
        if ($baseKey === null) {
            $this->replyOldButton($user, $callbackId);

            return;
        }

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
     * @param  array{scope:string,period?:string,log:?ReminderLog,baseKey:string,phase:string,index:int,opened?:bool}  $session  opened is true when the wird was just opened, not paged
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
            $this->show($user, $text, $markup, $messageId);
        } catch (Throwable $exception) {
            $this->recordReadingFailure($user, $session, $exception);

            return;
        }

        if ($isLast && $session['scope'] === 'today') {
            $this->events->record($user, 'reading_completed', ['scope' => 'manual', 'collection' => $headerKey]);
        }

        $opened = $session['opened'] ?? false;
        $log = $session['log'] instanceof ReminderLog ? $session['log'] : null;
        $source = match (true) {
            $session['phase'] === 'f' => 'continue',
            $log === null => 'manual',
            ! $opened && $user->sendsReadingWithReminder() => 'direct',
            default => 'reminder',
        };
        $this->readings->record($user, $headerKey, $session['phase'], $source, $log?->id, $index, $total, $opened);
    }

    /**
     * @param  array{scope:string,period?:string,log:?ReminderLog,baseKey:string,phase:string,index:int}  $session
     * @return array{prev?:string,next?:string,switch_to_full?:string,switch_to_short?:string,continue?:string,done?:string,save?:string}
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

        if ($index === 0 && in_array($session['phase'], ['s', 'a'], true) && ! $this->content->isSingleVersion($session['baseKey'])) {
            $target = $session['phase'] === 's' ? 'a' : 's';
            $callback = $session['scope'] === 'log' && $session['log'] instanceof ReminderLog
                ? 'rsw:'.$session['log']->id.':'.$target
                : 'md:'.($session['period'] ?? 'm').':'.$target;
            $actions[$target === 'a' ? 'switch_to_full' : 'switch_to_short'] = $callback;
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

    /**
     * Single-version manual collections open straight on their first card, with no mode choice.
     */
    private function startManualReading(User $user, string $period, ?int $messageId = null): void
    {
        $this->presentReading($user, [
            'scope' => 'today',
            'period' => $period,
            'log' => null,
            'baseKey' => (string) $this->content->manualBaseKey($period, 'a'),
            'phase' => 'a',
            'index' => 0,
            'opened' => true,
        ], $messageId);
    }

    /**
     * Opens straight on the remembered wird, or asks short or full the first time.
     */
    private function openPeriod(User $user, ReminderType $type, ?int $messageId): void
    {
        $period = $type === ReminderType::Evening ? 'e' : 'm';
        $remembered = $user->rememberedMode();
        if ($remembered !== null) {
            $this->startPeriodReading($user, $period, $remembered, $messageId);

            return;
        }

        $this->show($user, $this->content->modeChoiceText($type), $this->keyboards->modeChoice($period), $messageId);
    }

    private function startPeriodReading(User $user, string $period, AdhkarMode $mode, ?int $messageId): void
    {
        $phase = $mode === AdhkarMode::Full ? 'a' : 's';
        $this->presentReading($user, [
            'scope' => 'today',
            'period' => $period,
            'log' => null,
            'baseKey' => (string) $this->content->manualBaseKey($period, $phase),
            'phase' => $phase,
            'index' => 0,
            'opened' => true,
        ], $messageId);
    }

    private function rememberMode(User $user, AdhkarMode $mode): void
    {
        $user->mode = $mode;
        $user->mode_chosen_at = now();
        $user->save();
    }

    private function handleLogSwitch(User $user, string $data, string $callbackId, ?int $messageId): void
    {
        $parts = explode(':', $data);
        $phase = $parts[2] ?? '';
        $log = ReminderLog::query()->where('id', (int) ($parts[1] ?? 0))->where('user_id', $user->id)->first();
        if ($log === null || ! in_array($phase, ['s', 'a'], true)) {
            $this->safeAnswer($callbackId, __('bot.reminder_not_found'));

            return;
        }

        if ($log->reading_started_at === null) {
            $this->handleReminderStart($user, $log->id, $phase, $callbackId, $messageId);

            return;
        }

        if ($log->status === ReminderLogStatus::Completed) {
            $this->safeAnswer($callbackId, __('bot.already_completed'));

            return;
        }

        if (! $this->isCurrentLocalDay($user, $log)) {
            $this->safeAnswer($callbackId);
            $this->reply($user, __('bot.reminder_expired'), $this->keyboards->todayShortcut());

            return;
        }

        $mode = $phase === 'a' ? AdhkarMode::Full : AdhkarMode::Short;
        $switched = DB::transaction(function () use ($log, $mode) {
            $locked = ReminderLog::query()->whereKey($log->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status === ReminderLogStatus::Completed) {
                return false;
            }

            $key = $this->content->collectionKey($mode, $locked->reminder_type);
            $collection = AdhkarCollection::query()->where('key', $key)->where('active', true)->first();
            if ($collection === null) {
                return false;
            }

            $snapshot = $this->content->snapshotVersions($collection->items()->where('adhkar_items.active', true)->get());
            $locked->collection_key = $key;
            $locked->content_version_map = $snapshot['content_version_map'];
            $locked->content_snapshot_hash = $snapshot['content_snapshot_hash'];
            $locked->save();

            return true;
        });

        if (! $switched) {
            $this->safeAnswer($callbackId, __('bot.content_missing'));

            return;
        }

        $this->safeAnswer($callbackId);
        $this->rememberMode($user, $mode);
        $this->deliverScheduledReading($user, $log->refresh(), $messageId);
    }

    private function showHelpMenu(User $user, ?int $messageId): void
    {
        $this->show($user, __('bot.help'), $this->keyboards->helpMenu($user->isOnboarded()), $messageId);
    }

    private function showHelpTopic(User $user, string $key, ?int $messageId): void
    {
        $topics = __('bot.help_topics');
        if (! isset($topics[$key])) {
            $this->showHelpMenu($user, $messageId);

            return;
        }

        $this->events->record($user, 'help_topic', ['topic' => $key]);
        $answer = __('bot.help_topics.'.$key.'.answer', [
            'short' => __('bot.duration_short'),
            'full' => __('bot.duration_full'),
        ]);
        $this->show($user, $answer, $this->keyboards->helpTopic($key, $user->isOnboarded()), $messageId);
    }

    private function showTodayMenu(User $user, ?int $messageId): void
    {
        $period = DayPeriod::forUser($user);
        $text = __('bot.today_prompt', ['greeting' => __('bot.greeting.'.($period ?? 'default'))]);
        $this->show($user, $text, $this->keyboards->todayMenu($period), $messageId);
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
            if ($log === null || ! in_array($phase, ['s', 'f', 'a'], true)) {
                $this->safeAnswer($callbackId, __('bot.reminder_not_found'));

                return;
            }
            if ($log->reading_started_at === null) {
                $this->safeAnswer($callbackId, __('bot.start_reading_first'));

                return;
            }
            $session = [
                'baseKey' => (string) $log->collection_key,
                'phase' => $phase,
            ];
        } elseif ($kind === 't') {
            $phase = $parts[3] ?? '';
            $baseKey = $this->content->manualBaseKey($parts[2] ?? '', $phase);
            if ($baseKey === null) {
                $this->replyOldButton($user, $callbackId);

                return;
            }
            $session = [
                'baseKey' => $baseKey,
                'phase' => $phase,
            ];
        }

        if ($session === null) {
            $this->replyOldButton($user, $callbackId);

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
        $fullOnly = $session['phase'] === 'f';
        $this->events->record($user, 'wird_saved', [
            'collection' => $headerKey,
            'format' => str_starts_with($data, 'sv:') ? 'images' : 'pdf',
        ]);

        try {
            if (str_starts_with($data, 'sv:')
                && $this->wirds->sendImages($user->chat_id, $headerKey, $items, $fullOnly, 'sp:'.substr($data, 3))) {
                return;
            }
            $this->wirds->sendPdf($user->chat_id, $headerKey, $items, $fullOnly);

            return;
        } catch (Throwable $exception) {
            Log::channel('telegram')->error('Wird PDF delivery failed', [
                'user_id' => $user->id,
                'error' => TelegramClient::redact($exception->getMessage()),
            ]);
        }

        try {
            $this->telegram->sendMessage($user->chat_id, __('bot.pdf_failed'));
            foreach ($this->content->offlineMessages($headerKey, $items) as $message) {
                $this->telegram->sendMessage($user->chat_id, $message);
            }
        } catch (Throwable $exception) {
            Log::channel('telegram')->error('Offline reading delivery failed', [
                'user_id' => $user->id,
                'error' => TelegramClient::redact($exception->getMessage()),
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
            'error' => TelegramClient::redact($exception->getMessage()),
        ]);

        if ($session['log'] instanceof ReminderLog) {
            $session['log']->error_message = Str::limit($exception->getMessage(), 1000);
            $session['log']->save();
        }

        $this->reply($user, __('bot.reading_failed'), $this->keyboards->todayShortcut());
    }

    private function showSettingsSaved(User $user, string $lead, ?int $messageId): void
    {
        $fresh = $user->fresh() ?? $user;
        $this->show(
            $fresh,
            $lead."\n\n".$this->onboarding->summary($fresh),
            $this->keyboards->settingsMenu($fresh),
            $messageId,
        );
    }

    private function sendSettings(User $user, ?int $messageId = null): void
    {
        if (! $user->isOnboarded()) {
            $this->reply($user, __('bot.need_start'));

            return;
        }

        $this->show(
            $user,
            __('bot.settings_header')."\n\n".$this->onboarding->summary($user),
            $this->keyboards->settingsMenu($user),
            $messageId,
        );
    }

    private function handlePause(User $user, ?int $messageId = null): void
    {
        $this->showPauseResume($user, $this->settings->pause($user), $messageId);
    }

    private function handleResume(User $user, ?int $messageId = null): void
    {
        $this->showPauseResume($user, $this->settings->resume($user), $messageId);
    }

    private function askPauseConfirmation(User $user, ?int $messageId = null): void
    {
        if (! $user->isOnboarded()) {
            $this->reply($user, __('bot.need_start'));

            return;
        }

        if (! $user->reminders_enabled) {
            $this->show($user, __('bot.already_paused'), $this->keyboards->settingsMenu($user), $messageId);

            return;
        }

        $this->show($user, __('bot.pause_confirm'), $this->keyboards->pauseConfirm(), $messageId);
    }

    private function showPauseResume(User $user, string $result, ?int $messageId): void
    {
        $user = $user->fresh() ?? $user;
        if (! $user->isOnboarded()) {
            $this->reply($user, __('bot.need_start'));

            return;
        }

        if ($result === 'resumed') {
            $this->events->record($user, 'resumed', ['seconds_after_pause' => $this->events->secondsSinceLastPause($user)]);
        } elseif ($result === 'paused') {
            $this->events->record($user, 'paused');
        }

        [$text, $markup] = match ($result) {
            'paused' => [__('bot.paused'), $this->keyboards->pausedUndo()],
            'already_paused' => [__('bot.already_paused'), $this->keyboards->pausedUndo()],
            'resumed' => [__('bot.resumed', [
                'morning' => $user->formattedMorningTime() ?: '—',
                'evening' => $user->formattedEveningTime() ?: '—',
            ]), $this->keyboards->settingsMenu($user)],
            'already_active' => [__('bot.already_active'), $this->keyboards->settingsMenu($user)],
            default => [__('bot.need_start'), null],
        };

        $this->show($user, $text, $markup, $messageId);
    }

    /**
     * @param  array{text:string,reply_markup:?array}  $response
     */
    private function sendResponse(User $user, array $response, ?int $messageId = null): void
    {
        $this->show($user, $response['text'], $response['reply_markup'], $messageId);
    }

    /**
     * Replaces the pressed message in place. Telegram cannot attach a bottom keyboard while editing,
     * so screens that carry one, or a failed edit, go out as a new message.
     */
    private function show(User $user, string $text, ?array $markup, ?int $messageId): void
    {
        $markup ??= [];
        if ($messageId !== null && ! isset($markup['keyboard'])) {
            try {
                $this->telegram->editMessageText($user->chat_id, $messageId, $text, $markup);

                return;
            } catch (Throwable $exception) {
                if (str_contains($exception->getMessage(), 'message is not modified')) {
                    return;
                }
            }
        }

        $this->telegram->sendMessage($user->chat_id, $text, $markup);
    }

    private function reply(User $user, string $text, ?array $replyMarkup = null): void
    {
        $this->telegram->sendMessage($user->chat_id, $text, $replyMarkup ?? []);
    }

    /**
     * User text echoed back inside an HTML message.
     */
    private function quote(string $text): string
    {
        return e(Str::limit(trim($text), 40));
    }

    /**
     * For digits and punctuation on a line of their own: the leading left-to-right mark makes Telegram
     * lay the line out left to right, so "2;01" is not shown as "01;2" inside the Arabic message.
     */
    private function quoteLtr(string $text): string
    {
        return "\u{200E}".$this->quote($text);
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
