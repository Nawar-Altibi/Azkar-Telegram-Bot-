<?php

namespace App\Enums;

enum OnboardingState: string
{
    case New = 'new';
    case WaitingTimezone = 'waiting_timezone';
    case WaitingMorningTime = 'waiting_morning_time';
    case WaitingEveningTime = 'waiting_evening_time';
    case WaitingMode = 'waiting_mode';
    case Completed = 'completed';
}
