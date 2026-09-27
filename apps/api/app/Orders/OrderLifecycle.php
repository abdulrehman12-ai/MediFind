<?php

declare(strict_types=1);

namespace App\Orders;

use App\Enums\OrderRequestMode;
use App\Enums\OrderStatus;

final class OrderLifecycle
{
    /**
     * Future pharmacy edges are defined here and are not HTTP endpoints.
     * Phase 5 only creates pending_pharmacy_review and follows the cancelled edge.
     *
     * @var array<string, list<string>>
     */
    public const EDGES = [
        OrderStatus::PendingPharmacyReview->value => [
            OrderStatus::Confirmed->value,
            OrderStatus::Rejected->value,
            OrderStatus::Cancelled->value,
        ],
        OrderStatus::Confirmed->value => [
            OrderStatus::ReadyForPickup->value,
            OrderStatus::Cancelled->value,
        ],
        OrderStatus::ReadyForPickup->value => [
            OrderStatus::Collected->value,
        ],
        OrderStatus::Collected->value => [],
        OrderStatus::Rejected->value => [],
        OrderStatus::Cancelled->value => [],
    ];

    public static function customerMayCancel(OrderStatus $status): bool
    {
        return in_array(OrderStatus::Cancelled->value, self::EDGES[$status->value], true);
    }

    /**
     * @return list<string>
     */
    public static function notices(OrderStatus $status, OrderRequestMode $mode): array
    {
        $notices = match ($status) {
            OrderStatus::PendingPharmacyReview => [
                'Awaiting pharmacist review.',
                'The price is an estimate until the pharmacy confirms the order.',
                'Cash is paid at pickup, not at checkout.',
            ],
            OrderStatus::Confirmed => [
                'The pharmacy confirmed this order.',
                'Cash is paid at pickup, not at checkout.',
            ],
            OrderStatus::ReadyForPickup => [
                'The order is ready for pickup.',
                'Cash is paid at pickup, not at checkout.',
            ],
            OrderStatus::Collected => [
                'This order was collected.',
            ],
            OrderStatus::Rejected => [
                'The pharmacy did not accept this order.',
            ],
            OrderStatus::Cancelled => [
                'This order was cancelled.',
            ],
        };

        if ($mode === OrderRequestMode::PrescriptionFullImage) {
            $notices[] = 'Sharing the prescription image does not mean its contents have been identified or are in stock.';
        }

        return $notices;
    }
}
