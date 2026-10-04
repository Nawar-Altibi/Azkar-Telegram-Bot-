<?php

namespace App\Enums;

enum AdhkarCategory: string
{
    case Morning = 'morning';
    case Evening = 'evening';
    case Additional = 'additional';
    case Bedtime = 'bedtime';
    case Waking = 'waking';
    case AfterPrayer = 'after_prayer';
}
