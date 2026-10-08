<?php

namespace App\Services;

use App\Models\Feedback;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use App\Support\Telegram\TelegramMessage;
use App\Support\TimezoneCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Messages users send through «أرسل ملاحظة» reach the owner's chat, and the owner's replies go back to them.
 */
class FeedbackService
{
    // A forgotten feedback prompt must not swallow ordinary messages the next day.
    private const OPEN_MINUTES = 30;

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly BotEventRecorder $events,
    ) {}

    public function open(User $user): void
    {
        Cache::put($this->openKey($user), true, now()->addMinutes(self::OPEN_MINUTES));
    }

    public function isOpen(User $user): bool
    {
        return Cache::has($this->openKey($user));
    }

    public function close(User $user): void
    {
        Cache::forget($this->openKey($user));
    }

    public function isAdminChat(int $chatId): bool
    {
        $admin = config('telegram.admin_chat_id');

        return $admin !== null && (int) $admin === $chatId;
    }

    /**
     * @return 'sent'|'limited'
     */
    public function receive(User $user, TelegramMessage $message): string
    {
        $this->close($user);

        $limiterKey = 'feedback:'.$user->id;
        if (RateLimiter::tooManyAttempts($limiterKey, max(1, (int) config('telegram.feedback_per_hour', 5)))) {
            return 'limited';
        }
        RateLimiter::hit($limiterKey, 3600);

        $feedback = Feedback::query()->create([
            'user_id' => $user->id,
            'message_id' => $message->messageId,
            'kind' => $message->kind,
            'text' => ($body = $message->text ?? $message->caption) !== null ? Str::limit($body, 4000) : null,
        ]);
        $this->events->record($user, 'feedback_sent', ['kind' => $message->kind]);
        $this->deliverToAdmin($user, $feedback);

        return 'sent';
    }

    /**
     * True when the admin's message was a reply to a feedback and has been passed on to its author.
     */
    public function relayAdminReply(TelegramMessage $message): bool
    {
        if ($message->replyToMessageId === null) {
            return false;
        }

        $feedback = Feedback::query()
            ->where('admin_header_message_id', $message->replyToMessageId)
            ->orWhere('admin_copy_message_id', $message->replyToMessageId)
            ->with('user')
            ->first();
        if ($feedback === null || $feedback->user === null) {
            return false;
        }

        $user = $feedback->user;
        $name = e($user->first_name ?: __('bot.feedback_someone'));
        try {
            $this->telegram->sendMessage($user->chat_id, __('bot.feedback_reply_header'));
            $this->telegram->copyMessage($user->chat_id, $message->chatId, $message->messageId);
            $feedback->replied_at = now();
            $feedback->save();
            $this->telegram->sendMessage($message->chatId, __('bot.feedback_reply_sent', ['name' => $name]));
        } catch (Throwable $exception) {
            Log::channel('telegram')->warning('Feedback reply not delivered', [
                'feedback_id' => $feedback->id,
                'error' => TelegramClient::redact($exception->getMessage()),
            ]);
            $this->telegram->sendMessage($message->chatId, __('bot.feedback_reply_failed', ['name' => $name]));
        }

        return true;
    }

    private function deliverToAdmin(User $user, Feedback $feedback): void
    {
        $admin = config('telegram.admin_chat_id');
        if ($admin === null) {
            return;
        }

        try {
            $header = $this->telegram->sendMessage($admin, $this->adminHeader($user, $feedback));
            $headerId = data_get($header, 'result.message_id');
            $copy = $this->telegram->copyMessage($admin, $user->chat_id, (int) $feedback->message_id, $headerId !== null
                ? ['reply_parameters' => json_encode(['message_id' => $headerId, 'allow_sending_without_reply' => true])]
                : []);
            $feedback->admin_header_message_id = $headerId;
            $feedback->admin_copy_message_id = data_get($copy, 'result.message_id');
            $feedback->save();
        } catch (Throwable $exception) {
            Log::channel('telegram')->warning('Feedback not delivered to admin', [
                'feedback_id' => $feedback->id,
                'error' => TelegramClient::redact($exception->getMessage()),
            ]);
        }
    }

    private function adminHeader(User $user, Feedback $feedback): string
    {
        $region = $user->timezone_label ?: TimezoneCatalog::labelForTimezone($user->timezone) ?: '—';

        return __('bot.feedback_admin_header', [
            'id' => $feedback->id,
            'name' => e($user->first_name ?: __('bot.feedback_someone')),
            'username' => $user->username ? ' · @'.e($user->username) : '',
            'telegram_id' => $user->telegram_id,
            'region' => e($region),
            'reminders' => __($user->reminders_enabled ? 'bot.reminders_on' : 'bot.reminders_off'),
            'since' => $user->created_at?->toDateString() ?? '—',
        ]);
    }

    private function openKey(User $user): string
    {
        return 'feedback_open:'.$user->id;
    }
}
