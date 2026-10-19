<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function telegramMessagePayload(string $text, int $updateId = 1, int $userId = 1001): array
    {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $updateId,
                'date' => time(),
                'chat' => ['id' => $userId, 'type' => 'private'],
                'from' => [
                    'id' => $userId,
                    'is_bot' => false,
                    'first_name' => 'نوار',
                    'username' => 'nawar',
                    'language_code' => 'ar',
                ],
                'text' => $text,
            ],
        ];
    }

    /**
     * A message without text, such as a voice note or a shared location.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    protected function telegramMediaPayload(array $fields, int $updateId = 1, int $userId = 1001): array
    {
        $payload = $this->telegramMessagePayload('', $updateId, $userId);
        unset($payload['message']['text']);
        $payload['message'] += $fields;

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    protected function telegramCallbackPayload(string $data, int $updateId = 2, int $userId = 1001): array
    {
        return [
            'update_id' => $updateId,
            'callback_query' => [
                'id' => 'cb-'.$updateId,
                'from' => [
                    'id' => $userId,
                    'is_bot' => false,
                    'first_name' => 'نوار',
                    'username' => 'nawar',
                    'language_code' => 'ar',
                ],
                'message' => [
                    'message_id' => 10,
                    'date' => time(),
                    'chat' => ['id' => $userId, 'type' => 'private'],
                    'from' => ['id' => 1, 'is_bot' => true, 'first_name' => 'Azkar'],
                    'text' => '…',
                ],
                'data' => $data,
            ],
        ];
    }

    protected function webhookUrl(): string
    {
        return '/telegram/webhook/test-secret';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function postWebhook(array $payload)
    {
        return $this->postJson($this->webhookUrl(), $payload, [
            'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
        ]);
    }
}
