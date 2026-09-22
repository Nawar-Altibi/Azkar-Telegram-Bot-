<?php

namespace App\Support\Telegram;

final class TelegramMessage
{
    public function __construct(
        public readonly int $messageId,
        public readonly int $chatId,
        public readonly ?TelegramUser $from,
        public readonly ?string $text,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            messageId: (int) ($data['message_id'] ?? 0),
            chatId: (int) data_get($data, 'chat.id', 0),
            from: isset($data['from']) ? TelegramUser::fromArray($data['from']) : null,
            text: $data['text'] ?? null,
        );
    }
}
