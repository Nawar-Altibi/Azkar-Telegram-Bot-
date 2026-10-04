<?php

return [
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    'webhook_url' => env('TELEGRAM_WEBHOOK_URL'),
    'api_base' => env('TELEGRAM_API_BASE', 'https://api.telegram.org'),
    'timeout' => 15,
    // A second press of the same button on the same message within this window is ignored.
    'double_press_seconds' => (int) env('TELEGRAM_DOUBLE_PRESS_SECONDS', 2),
];
