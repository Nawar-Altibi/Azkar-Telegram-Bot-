<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response('Azkar Bot is running.', 200, ['Content-Type' => 'text/plain; charset=UTF-8']));
