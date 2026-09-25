<?php

declare(strict_types=1);

namespace App\Enums;

enum MedicineStatus: string
{
    case Inactive = 'inactive';
    case Active = 'active';
}
