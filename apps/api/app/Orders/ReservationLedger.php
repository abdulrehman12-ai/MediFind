<?php

declare(strict_types=1);

namespace App\Orders;

use App\Enums\StockStatus;
use App\Exceptions\ApiProblem;
use App\Models\Order;
use App\Models\OrderReservation;
use App\Models\PharmacyInventory;
use Illuminate\Support\Collection;

/**
 * Available quantity is quantity_on_hand minus active reservations.
 * Confirmation inserts an active row and does not change on-hand.
 * Release marks the row released and does not change on-hand.
 * Collection marks the row consumed and decrements on-hand once.
 */
final class ReservationLedger
{
    /**
     * @param  list<string>  $medicineIds
     * @return Collection<string, PharmacyInventory>
     */
    public function lockInventories(string $pharmacyId, array $medicineIds): Collection
    {
        $ids = array_values(array_unique($medicineIds));
        sort($ids);

        if ($ids === []) {
            return new Collection;
        }

        return PharmacyInventory::query()
            ->where('pharmacy_id', $pharmacyId)
            ->whereIn('medicine_id', $ids)
            ->orderBy('medicine_id')
            ->lockForUpdate()
            ->get()
            ->keyBy(static fn (PharmacyInventory $row): string => strtolower((string) $row->medicine_id));
    }

    public function available(PharmacyInventory $inventory): int
    {
        if ($inventory->quantity_on_hand === null) {
            throw new ApiProblem('insufficient_stock', 'This order needs a counted quantity before it can be confirmed.', 409);
        }

        $reserved = (int) OrderReservation::query()
            ->where('inventory_id', $inventory->id)
            ->where('state', 'active')
            ->sum('quantity');

        return $inventory->quantity_on_hand - $reserved;
    }

    /**
     * @param  array{order_item_id: ?string, quote_line_id: ?string}  $line
     */
    public function createActive(Order $order, PharmacyInventory $inventory, string $medicineId, int $quantity, array $line): void
    {
        $row = new OrderReservation;
        $row->forceFill([
            'order_id' => $order->id,
            'inventory_id' => $inventory->id,
            'medicine_id' => $medicineId,
            'order_item_id' => $line['order_item_id'],
            'quote_line_id' => $line['quote_line_id'],
            'quantity' => $quantity,
            'state' => 'active',
            'created_at' => now(),
        ])->save();
    }

    /**
     * @param  list<string>  $orderIds
     */
    public function releaseOrders(array $orderIds): void
    {
        if ($orderIds === []) {
            return;
        }

        $existing = OrderReservation::query()
            ->whereIn('order_id', $orderIds)
            ->orderBy('id')
            ->get();

        if ($existing->isEmpty()) {
            return;
        }

        $inventoryIds = $existing->pluck('inventory_id')->unique()->sort()->values()->all();
        PharmacyInventory::query()
            ->whereIn('id', $inventoryIds)
            ->orderBy('medicine_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $locked = OrderReservation::query()
            ->whereIn('order_id', $orderIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($locked as $row) {
            if ($row->state !== 'active') {
                throw new ApiProblem('request_not_completed', 'The reservation could not be released.', 409);
            }

            $row->state = 'released';
            $row->save();
        }
    }

    public function consume(Order $order): void
    {
        $existing = OrderReservation::query()
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get();

        if ($existing->isEmpty()) {
            throw new ApiProblem('request_not_completed', 'This order has no reservation to collect.', 409);
        }

        $inventoryIds = $existing->pluck('inventory_id')->unique()->sort()->values()->all();
        $inventories = PharmacyInventory::query()
            ->whereIn('id', $inventoryIds)
            ->orderBy('medicine_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(static fn (PharmacyInventory $row): string => (string) $row->id);

        $locked = OrderReservation::query()
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $taken = [];

        foreach ($locked as $row) {
            if ($row->state !== 'active') {
                throw new ApiProblem('request_not_completed', 'This order has no reservation to collect.', 409);
            }

            $row->state = 'consumed';
            $row->save();
            $inventoryId = (string) $row->inventory_id;
            $taken[$inventoryId] = ($taken[$inventoryId] ?? 0) + (int) $row->quantity;
        }

        foreach ($inventories as $inventory) {
            $quantity = $taken[(string) $inventory->id] ?? 0;

            if ($quantity === 0 || $inventory->quantity_on_hand === null) {
                throw new ApiProblem('request_not_completed', 'This order has no reservation to collect.', 409);
            }

            $remaining = $inventory->quantity_on_hand - $quantity;

            if ($remaining < 0) {
                throw new ApiProblem('insufficient_stock', 'This order needs a counted quantity before it can be confirmed.', 409);
            }

            $values = [
                'quantity_on_hand' => $remaining,
                'version' => ((int) $inventory->version) + 1,
                'updated_at' => now(),
            ];

            if ($remaining === 0) {
                $values['stock_status'] = StockStatus::OutOfStock->value;
                $values['is_available_for_pickup'] = false;
            }

            PharmacyInventory::query()->whereKey($inventory->id)->update($values);
        }
    }
}
