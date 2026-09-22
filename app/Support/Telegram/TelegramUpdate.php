<?php

namespace App\Support\Telegram;

final class TelegramUpdate
{
    public function __construct(
        public readonly int $updateId,
        public readonly ?TelegramMessage $message,
        public readonly ?TelegramCallbackQuery $callbackQuery,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            updateId: (int) ($data['update_id'] ?? 0),
            message: isset($data['message']) ? TelegramMessage::fromArray($data['message']) : null,
            callbackQuery: isset($data['callback_query']) ? TelegramCallbackQuery::fromArray($data['callback_query']) : null,
        );
    }

    public function chatId(): ?int
    {
        return $this->message?->chatId
            ?? $this->callbackQuery?->message?->chatId
            ?? $this->callbackQuery?->from?->id;
    }

    public function from(): ?TelegramUser
    {
        return $this->message?->from ?? $this->callbackQuery?->from;
    }
}
