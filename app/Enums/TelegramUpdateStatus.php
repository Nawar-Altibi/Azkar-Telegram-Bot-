<?php

namespace App\Enums;

enum TelegramUpdateStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Processed = 'processed';
    case Failed = 'failed';
}
