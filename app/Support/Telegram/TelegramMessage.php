<?php

namespace App\Support\Telegram;

final class TelegramMessage
{
    private const KINDS = [
        'voice', 'audio', 'photo', 'sticker', 'video', 'video_note', 'animation', 'document', 'contact', 'location',
    ];

    /**
     * @param  array{latitude:float,longitude:float}|null  $location
     */
    public function __construct(
        public readonly int $messageId,
        public readonly int $chatId,
        public readonly ?TelegramUser $from,
        public readonly ?string $text,
        public readonly string $kind = 'text',
        public readonly ?array $location = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $kind = isset($data['text']) ? 'text' : 'other';
        foreach (self::KINDS as $candidate) {
            if ($kind === 'other' && isset($data[$candidate])) {
                $kind = $candidate;
            }
        }

        $location = null;
        if (isset($data['location']['latitude'], $data['location']['longitude'])) {
            $location = [
                'latitude' => (float) $data['location']['latitude'],
                'longitude' => (float) $data['location']['longitude'],
            ];
        }

        return new self(
            messageId: (int) ($data['message_id'] ?? 0),
            chatId: (int) data_get($data, 'chat.id', 0),
            from: isset($data['from']) ? TelegramUser::fromArray($data['from']) : null,
            text: $data['text'] ?? null,
            kind: $kind,
            location: $location,
        );
    }
}
