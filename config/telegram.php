<?php

return [
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    'webhook_url' => env('TELEGRAM_WEBHOOK_URL'),
    'api_base' => env('TELEGRAM_API_BASE', 'https://api.telegram.org'),
    'timeout' => 15,
    // Fail closed if no administrator IDs have been configured.
    'admin_ids' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('TELEGRAM_ADMIN_IDS', ''))),
        static fn (string $id) => $id !== '' && ctype_digit($id),
    )),
    'broadcast_batch_size' => (int) env('TELEGRAM_BROADCAST_BATCH_SIZE', 10),
    'broadcast_delay_microseconds' => (int) env('TELEGRAM_BROADCAST_DELAY_MICROSECONDS', 100_000),
];
