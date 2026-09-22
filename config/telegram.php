<?php

return [
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    'webhook_url' => env('TELEGRAM_WEBHOOK_URL'),
    'api_base' => env('TELEGRAM_API_BASE', 'https://api.telegram.org'),
    'timeout' => 15,
];
