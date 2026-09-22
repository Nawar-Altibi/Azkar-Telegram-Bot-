<?php

namespace App\Services\Telegram;

use RuntimeException;

class TelegramApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly array $payload = [],
    ) {
        parent::__construct($message, $httpStatus);
    }

    public function isBlockedByUser(): bool
    {
        $text = mb_strtolower($this->getMessage());

        return $this->httpStatus === 403
            || str_contains($text, 'blocked')
            || str_contains($text, 'bot was blocked')
            || str_contains($text, 'user is deactivated')
            || str_contains($text, 'chat not found');
    }
}
