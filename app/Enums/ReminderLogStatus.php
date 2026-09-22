<?php

namespace App\Enums;

enum ReminderLogStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Completed = 'completed';
    case Skipped = 'skipped';
}
