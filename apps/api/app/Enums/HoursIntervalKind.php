<?php

declare(strict_types=1);

namespace App\Enums;

enum HoursIntervalKind: string
{
    case SameDay = 'same_day';
    case Overnight = 'overnight';
    case AllDay = 'all_day';
}
