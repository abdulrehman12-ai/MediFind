<?php

declare(strict_types=1);

namespace App\Enums;

enum InvitationKind: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Pharmacist = 'pharmacist';
    case InventoryStaff = 'inventory_staff';
}
