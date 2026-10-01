<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TelegramClient
{
    /**
     * Connection errors embed the request URL, which contains the bot token.
     */
    public static function redact(string $message): string
    {
        $token = (string) config('telegram.bot_token');

        return $token === '' ? $message : str_replace($token, '[redacted]', $message);
    }

    public function sendMessage(int|string $chatId, string $text, array $replyMarkup = [], array $extra = []): array
    {
        $payload = array_filter([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => $replyMarkup === [] ? null : json_encode($replyMarkup, JSON_UNESCAPED_UNICODE),
        ] + $extra);

        return $this->call('sendMessage', $payload);
    }

    public function sendChatAction(int|string $chatId, string $action = 'typing'): array
    {
        return $this->call('sendChatAction', [
            'chat_id' => $chatId,
            'action' => $action,
        ]);
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text = '', bool $showAlert = false): array
    {
        return $this->call('answerCallbackQuery', array_filter([
            'callback_query_id' => $callbackQueryId,
            'text' => $text !== '' ? $text : null,
            'show_alert' => $showAlert,
        ], fn ($value) => $value !== null));
    }

    public function editMessageText(int|string $chatId, int $messageId, string $text, array $replyMarkup = []): array
    {
        return $this->call('editMessageText', array_filter([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => $replyMarkup === [] ? null : json_encode($replyMarkup, JSON_UNESCAPED_UNICODE),
        ]));
    }

    public function setWebhook(string $url, string $secret): array
    {
        return $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'drop_pending_updates' => false,
        ]);
    }

    public function deleteWebhook(): array
    {
        return $this->call('deleteWebhook', ['drop_pending_updates' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getMe(): array
    {
        return $this->call('getMe', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function getWebhookInfo(): array
    {
        return $this->call('getWebhookInfo', []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getUpdates(int $offset = 0, int $timeout = 25): array
    {
        // HTTP client must outlive Telegram's long-poll window.
        $result = $this->call('getUpdates', [
            'offset' => $offset,
            'timeout' => $timeout,
        ], $timeout + 15);

        return $result['result'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function call(string $method, array $payload, ?int $timeoutSeconds = null): array
    {
        $token = (string) config('telegram.bot_token');
        if ($token === '') {
            throw new RuntimeException('Telegram bot token is not configured.');
        }

        $url = rtrim((string) config('telegram.api_base'), '/').'/bot'.$token.'/'.$method;

        /** @var Response $response */
        $response = Http::timeout($timeoutSeconds ?? (int) config('telegram.timeout', 15))
            ->acceptJson()
            ->asForm()
            ->post($url, $payload);

        $json = $response->json() ?? [];

        if ($response->failed() || ($json['ok'] ?? false) !== true) {
            $description = $json['description'] ?? $response->body();
            Log::channel('telegram')->warning('Telegram API call failed', [
                'method' => $method,
                'status' => $response->status(),
                'description' => $description,
            ]);

            throw new TelegramApiException(
                is_string($description) ? $description : 'telegram_api_error',
                $response->status(),
                $json,
            );
        }

        return $json;
    }
}
