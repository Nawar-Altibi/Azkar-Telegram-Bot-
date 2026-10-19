<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('adhkar:dispatch-due-reminders')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('adhkar:post-due-groups')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('adhkar:recover-stale')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('adhkar:send-follow-ups')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('bot:prune-events')->daily();

Schedule::command('bot:prune-updates')->dailyAt('03:00')->withoutOverlapping();

Schedule::command('bot:weekly-report')->hourly();
