<?php

declare(strict_types=1);

namespace App\Inventory;

use App\Enums\StockStatus;

final class InventoryRules
{
    public static function assertState(
        StockStatus $status,
        ?int $quantity,
        bool $verified,
        bool $pickup,
        bool $controlled,
    ): void {
        if ($quantity !== null && $quantity < 0) {
            throw new InvalidInventory('Quantity cannot be negative.');
        }

        if ($controlled && $pickup) {
            throw new InvalidInventory('Controlled medicines are not available for pickup.');
        }

        $valid = match ($status) {
            StockStatus::Unknown => $quantity === null && ! $verified && ! $pickup,
            StockStatus::OutOfStock => $quantity === 0 && $verified && ! $pickup,
            StockStatus::LowStock => $quantity !== null && $quantity > 0 && $verified,
            StockStatus::InStock => ($quantity === null || $quantity > 0) && $verified,
        };

        if (! $valid) {
            throw new InvalidInventory('Stock status, quantity, and verification time do not match.');
        }

        if ($pickup && ! in_array($status, [StockStatus::InStock, StockStatus::LowStock], true)) {
            throw new InvalidInventory('Pickup can be offered only for in-stock or low-stock items.');
        }
    }
}
