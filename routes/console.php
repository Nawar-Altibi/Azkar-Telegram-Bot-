<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(\Illuminate\Foundation\Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('adhkar:dispatch-due-reminders')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('adhkar:recover-stale')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('adhkar:send-follow-ups')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('bot:prune-events')->daily();
