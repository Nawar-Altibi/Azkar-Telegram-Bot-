<?php

namespace App\Support\Telegram;

final class TelegramUser
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $firstName,
        public readonly ?string $username,
        public readonly ?string $languageCode,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) ($data['id'] ?? 0),
            firstName: $data['first_name'] ?? null,
            username: $data['username'] ?? null,
            languageCode: $data['language_code'] ?? null,
        );
    }
}
