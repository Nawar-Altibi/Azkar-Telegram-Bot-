<?php

namespace App\Support\Telegram;

final class TelegramCallbackQuery
{
    public function __construct(
        public readonly string $id,
        public readonly ?TelegramUser $from,
        public readonly ?TelegramMessage $message,
        public readonly string $data,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            from: isset($data['from']) ? TelegramUser::fromArray($data['from']) : null,
            message: isset($data['message']) ? TelegramMessage::fromArray($data['message']) : null,
            data: (string) ($data['data'] ?? ''),
        );
    }
}
