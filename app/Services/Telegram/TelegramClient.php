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

    /**
     * Uploads $path, or resends an earlier upload when $fileId is given.
     */
    public function sendDocument(
        int|string $chatId,
        string $path,
        string $filename,
        string $caption = '',
        ?string $fileId = null,
    ): array {
        $payload = array_filter([
            'chat_id' => $chatId,
            'caption' => $caption !== '' ? $caption : null,
            'parse_mode' => $caption !== '' ? 'HTML' : null,
        ]);

        if ($fileId !== null) {
            return $this->call('sendDocument', $payload + ['document' => $fileId]);
        }

        return $this->call('sendDocument', $payload, null, [[
            'name' => 'document',
            'contents' => (string) file_get_contents($path),
            'filename' => $filename,
        ]]);
    }

    /**
     * Sends 2 to 10 photos as one album. Each entry is a local path to upload, or a file_id from an earlier upload.
     *
     * @param  array<int, array{path?:string,file_id?:string}>  $photos
     */
    public function sendMediaGroup(int|string $chatId, array $photos, string $caption = ''): array
    {
        $media = [];
        $attachments = [];
        foreach (array_values($photos) as $i => $photo) {
            $entry = ['type' => 'photo'];
            if (isset($photo['file_id'])) {
                $entry['media'] = $photo['file_id'];
            } else {
                $entry['media'] = 'attach://page'.$i;
                $attachments[] = [
                    'name' => 'page'.$i,
                    'contents' => (string) file_get_contents((string) $photo['path']),
                    'filename' => 'page'.$i.'.png',
                ];
            }
            if ($i === 0 && $caption !== '') {
                $entry['caption'] = $caption;
                $entry['parse_mode'] = 'HTML';
            }
            $media[] = $entry;
        }

        return $this->call('sendMediaGroup', [
            'chat_id' => $chatId,
            'media' => json_encode($media, JSON_UNESCAPED_UNICODE),
        ], 60, $attachments);
    }

    /**
     * @param  array{path?:string,file_id?:string}  $photo
     */
    public function sendPhoto(int|string $chatId, array $photo, string $caption = ''): array
    {
        $payload = array_filter([
            'chat_id' => $chatId,
            'caption' => $caption !== '' ? $caption : null,
            'parse_mode' => $caption !== '' ? 'HTML' : null,
        ]);

        if (isset($photo['file_id'])) {
            return $this->call('sendPhoto', $payload + ['photo' => $photo['file_id']]);
        }

        return $this->call('sendPhoto', $payload, 60, [[
            'name' => 'photo',
            'contents' => (string) file_get_contents((string) $photo['path']),
            'filename' => 'page.png',
        ]]);
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

    /**
     * @param  array<int, array{command:string,description:string}>  $commands
     */
    public function setMyCommands(array $commands, ?string $languageCode = null, ?string $scope = null): array
    {
        return $this->call('setMyCommands', array_filter([
            'commands' => json_encode($commands, JSON_UNESCAPED_UNICODE),
            'language_code' => $languageCode,
            'scope' => $scope !== null ? json_encode(['type' => $scope]) : null,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function getChatMember(int|string $chatId, int $userId): array
    {
        return $this->call('getChatMember', [
            'chat_id' => $chatId,
            'user_id' => $userId,
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
     * @param  array<int, array{name:string,contents:string,filename:string}>  $attachments  sent as multipart instead of a form
     * @return array<string, mixed>
     */
    private function call(string $method, array $payload, ?int $timeoutSeconds = null, array $attachments = []): array
    {
        $token = (string) config('telegram.bot_token');
        if ($token === '') {
            throw new RuntimeException('Telegram bot token is not configured.');
        }

        $url = rtrim((string) config('telegram.api_base'), '/').'/bot'.$token.'/'.$method;

        $request = Http::timeout($timeoutSeconds ?? (int) config('telegram.timeout', 15))->acceptJson();
        if ($attachments === []) {
            $request = $request->asForm();
        }
        foreach ($attachments as $attachment) {
            $request = $request->attach($attachment['name'], $attachment['contents'], $attachment['filename']);
        }

        /** @var Response $response */
        $response = $request->post($url, $payload);

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
