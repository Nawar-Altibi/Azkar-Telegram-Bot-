<?php

return [
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    'webhook_url' => env('TELEGRAM_WEBHOOK_URL'),
    'api_base' => env('TELEGRAM_API_BASE', 'https://api.telegram.org'),
    'timeout' => 15,
    // A second press of the same button on the same message within this window is ignored.
    'double_press_seconds' => (int) env('TELEGRAM_DOUBLE_PRESS_SECONDS', 2),
    // The owner's private chat: user feedback is copied here, replies to it go back to the user, and /stats answers only here.
    'admin_chat_id' => filled(env('TELEGRAM_ADMIN_CHAT_ID')) ? (int) env('TELEGRAM_ADMIN_CHAT_ID') : null,
    'feedback_per_hour' => (int) env('TELEGRAM_FEEDBACK_PER_HOUR', 5),
];
