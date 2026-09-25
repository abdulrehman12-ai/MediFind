<?php

declare(strict_types=1);

namespace App\Enums;

enum InvitationDeliveryStatus: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';
}
