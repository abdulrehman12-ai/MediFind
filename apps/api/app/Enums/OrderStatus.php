<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderStatus: string
{
    case PendingPharmacyReview = 'pending_pharmacy_review';
    case Confirmed = 'confirmed';
    case ReadyForPickup = 'ready_for_pickup';
    case Collected = 'collected';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case NotCollected = 'not_collected';
    case Unfulfillable = 'unfulfillable';
}
