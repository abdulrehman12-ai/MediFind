<?php

declare(strict_types=1);

namespace App\Orders;

use App\Audit\AuditRecorder;
use App\Enums\MedicineStatus;
use App\Enums\OrderRequestMode;
use App\Enums\OrderStatus;
use App\Enums\StockStatus;
use App\Exceptions\ApiProblem;
use App\Models\Medicine;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderQuote;
use App\Models\OrderStatusEvent;
use App\Models\Pharmacy;
use App\Models\PharmacyInventory;
use App\Models\Prescription;
use App\Models\User;
use App\Support\TestingBarrier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lock order for pharmacist writes that touch a linked prescription:
 * pharmacy, acting user, membership, prescription, medicines by id,
 * order, quote, inventory by medicine id, reservations by id.
 * Catalog orders skip the prescription lock. Customer cancellation locks
 * the order, then inventory, then reservations, and does not lock the prescription.
 */
final class FulfillmentService
{
    /** @var list<string> */
    private array $lockedMedicineIds = [];

    public function __construct(
        private readonly FulfillmentAccess $access,
        private readonly ReservationLedger $reservations,
        private readonly OrderPrescriptionGuard $prescriptions,
        private readonly AuditRecorder $audit,
    ) {}

    public function confirm(User $pharmacist, Pharmacy $pharmacy, string $publicId): Order
    {
        try {
            return DB::transaction(fn (): Order => $this->confirmInside($pharmacist, $pharmacy, $publicId));
        } catch (QuoteNeedsInvalidation $invalidation) {
            $this->invalidate($invalidation);

            throw new ApiProblem('quote_invalid', 'This quote no longer matches the order. Ask the pharmacy for a new quote.', 409);
        } catch (QueryException $exception) {
            if ($this->stockConstraint($exception)) {
                throw new ApiProblem('insufficient_stock', 'Not enough unreserved stock is available.', 409);
            }

            throw $exception;
        }
    }

    public function reject(User $pharmacist, Pharmacy $pharmacy, string $publicId, string $reason): Order
    {
        return DB::transaction(function () use ($pharmacist, $pharmacy, $publicId, $reason): Order {
            $order = $this->lockOrder($pharmacist, $pharmacy, $publicId, false);

            if ($order->status === OrderStatus::Rejected) {
                return $order->load(['items', 'events']);
            }

            if ($order->status !== OrderStatus::PendingPharmacyReview) {
                throw new ApiProblem('order_not_pending', 'This order is no longer awaiting review.', 409);
            }

            $order->status = OrderStatus::Rejected;
            $order->save();
            $this->event($order, OrderStatus::Rejected, 'rejected', $pharmacist, $this->requiredReason($reason));
            $this->audit->record($pharmacist, 'order_rejected', 'order', (string) $order->id, (string) $order->pharmacy_id, [
                'status' => OrderStatus::Rejected->value,
            ]);

            return $order->load(['items', 'events']);
        });
    }

    public function markReady(User $pharmacist, Pharmacy $pharmacy, string $publicId, string $startsAt, string $endsAt): Order
    {
        return DB::transaction(function () use ($pharmacist, $pharmacy, $publicId, $startsAt, $endsAt): Order {
            $order = $this->lockOrder($pharmacist, $pharmacy, $publicId, false);

            if ($order->status === OrderStatus::ReadyForPickup) {
                return $order->load(['items', 'events']);
            }

            if ($order->status !== OrderStatus::Confirmed) {
                throw new ApiProblem('order_not_confirmed', 'Only a confirmed order can be marked ready.', 409);
            }

            $this->assertFulfillable($order);
            $start = $this->localInstant($startsAt);
            $end = $this->localInstant($endsAt);
            $clock = now()->utc();

            if ($start->lte($clock) || $end->lte($clock) || $end->lte($start)) {
                throw ValidationException::withMessages([
                    'pickup_window' => ['The pickup window must start and end in the future, with the end after the start.'],
                ]);
            }

            $grace = (int) config('medifind.orders.pickup_grace_seconds');
            $order->forceFill([
                'status' => OrderStatus::ReadyForPickup,
                'pickup_window_starts_at' => $start,
                'pickup_window_ends_at' => $end,
                'pickup_grace_seconds' => $grace,
                'collect_until' => $end->copy()->addSeconds($grace),
            ])->save();
            $this->event($order, OrderStatus::ReadyForPickup, 'ready_for_pickup', $pharmacist, null);
            $this->audit->record($pharmacist, 'order_ready', 'order', (string) $order->id, (string) $order->pharmacy_id, [
                'status' => OrderStatus::ReadyForPickup->value,
                'pickup_grace_seconds' => $grace,
            ]);

            return $order->load(['items', 'events']);
        });
    }

    public function collect(User $pharmacist, Pharmacy $pharmacy, string $publicId, int $cashPaisa): Order
    {
        try {
            return DB::transaction(fn (): Order => $this->collectInside($pharmacist, $pharmacy, $publicId, $cashPaisa));
        } catch (QueryException $exception) {
            if ($this->stockConstraint($exception)) {
                throw new ApiProblem('insufficient_stock', 'Not enough unreserved stock is available.', 409);
            }

            throw $exception;
        }
    }

    public function notCollected(User $pharmacist, Pharmacy $pharmacy, string $publicId, string $reason): Order
    {
        return DB::transaction(function () use ($pharmacist, $pharmacy, $publicId, $reason): Order {
            $order = $this->lockOrder($pharmacist, $pharmacy, $publicId, false);

            if ($order->status === OrderStatus::NotCollected) {
                return $order->load(['items', 'events']);
            }

            if ($order->status !== OrderStatus::ReadyForPickup) {
                throw new ApiProblem('order_not_ready', 'This order is not waiting for pickup.', 409);
            }

            if ($order->collect_until === null || now()->lt($order->collect_until)) {
                throw new ApiProblem('not_collected_too_early', 'A no-show can be recorded only after the pickup window and grace have ended.', 409);
            }

            $this->reservations->releaseOrders([(string) $order->id]);
            $order->status = OrderStatus::NotCollected;
            $order->save();
            $this->event($order, OrderStatus::NotCollected, 'not_collected', $pharmacist, $this->requiredReason($reason));
            $this->audit->record($pharmacist, 'order_not_collected', 'order', (string) $order->id, (string) $order->pharmacy_id, [
                'status' => OrderStatus::NotCollected->value,
                'reservation_released' => true,
            ]);

            return $order->load(['items', 'events']);
        });
    }

    private function confirmInside(User $pharmacist, Pharmacy $pharmacy, string $publicId): Order
    {
        $order = $this->lockOrder($pharmacist, $pharmacy, $publicId, true);

        if ($order->status === OrderStatus::Confirmed) {
            return $order->load(['items', 'events', 'quotes.lines']);
        }

        if ($order->status !== OrderStatus::PendingPharmacyReview) {
            throw new ApiProblem('order_not_pending', 'This order is no longer awaiting review.', 409);
        }

        $quote = OrderQuote::query()
            ->where('order_id', $order->id)
            ->where('state', 'accepted')
            ->lockForUpdate()
            ->first();
        $prescription = $this->lockedPrescription($order);
        $this->assertConfirmablePrescription($order, $prescription, $quote);
        $plan = $this->plan($order, $quote, $prescription);
        $plannedIds = array_map(static fn (array $line): string => strtolower($line['medicine_id']), $plan['lines']);
        $lockedIds = $this->lockedMedicineIds;
        sort($plannedIds);
        sort($lockedIds);

        if ($plannedIds !== $lockedIds) {
            throw new ApiProblem('quote_stale', 'Accept the current quote version.', 409);
        }

        $inventories = $this->reservations->lockInventories((string) $order->pharmacy_id, $plan['medicine_ids']);
        TestingBarrier::wait('after_confirmation_inventory_lock');

        foreach ($plan['lines'] as $line) {
            $medicineId = strtolower($line['medicine_id']);
            $inventory = $inventories->get($medicineId);
            $medicine = Medicine::query()->whereKey($line['medicine_id'])->first();

            if (! $inventory instanceof PharmacyInventory || ! $medicine instanceof Medicine) {
                throw new ApiProblem('medicine_unavailable', 'One or more selected medicines are not available for pickup.', 422);
            }

            $this->assertSellable($medicine, $inventory);

            if (! $this->sameIdentity($line, $medicine)) {
                if ($quote instanceof OrderQuote) {
                    throw new QuoteNeedsInvalidation((string) $order->id, (string) $quote->id);
                }

                throw new ApiProblem('medicine_identity_changed', 'A medicine on this order no longer matches the submitted request. Reject it so the customer can submit a new order.', 409);
            }

            if ((int) $inventory->unit_price_paisa !== $line['unit_price_paisa']) {
                if ($quote instanceof OrderQuote) {
                    throw new QuoteNeedsInvalidation((string) $order->id, (string) $quote->id);
                }

                throw new ApiProblem('quote_required', 'The price changed. Send a quote and wait for the customer to accept it.', 409);
            }

            if ($inventory->quantity_on_hand === null || $this->reservations->available($inventory) < $line['quantity']) {
                throw new ApiProblem('insufficient_stock', 'Not enough unreserved stock is available.', 409);
            }
        }

        foreach ($plan['lines'] as $line) {
            $inventory = $inventories->get(strtolower($line['medicine_id']));

            if (! $inventory instanceof PharmacyInventory) {
                throw new ApiProblem('medicine_unavailable', 'One or more selected medicines are not available for pickup.', 422);
            }

            $this->reservations->createActive($order, $inventory, $line['medicine_id'], $line['quantity'], [
                'order_item_id' => $line['order_item_id'],
                'quote_line_id' => $line['quote_line_id'],
            ]);
        }

        $order->forceFill([
            'status' => OrderStatus::Confirmed,
            'confirmed_total_paisa' => $plan['total'],
        ])->save();
        $this->event($order, OrderStatus::Confirmed, 'confirmed', $pharmacist, null);
        $this->audit->record($pharmacist, 'order_confirmed', 'order', (string) $order->id, (string) $order->pharmacy_id, [
            'status' => OrderStatus::Confirmed->value,
            'line_count' => count($plan['lines']),
        ]);

        return $order->load(['items', 'events', 'quotes.lines']);
    }

    private function collectInside(User $pharmacist, Pharmacy $pharmacy, string $publicId, int $cashPaisa): Order
    {
        $order = $this->lockOrder($pharmacist, $pharmacy, $publicId, false);

        if ($order->status === OrderStatus::Collected) {
            return $order->load(['items', 'events']);
        }

        if ($order->status !== OrderStatus::ReadyForPickup) {
            throw new ApiProblem('order_not_ready', 'This order is not waiting for pickup.', 409);
        }

        $this->assertFulfillable($order);

        if ($cashPaisa !== (int) $order->confirmed_total_paisa || $cashPaisa < 0) {
            throw new ApiProblem('cash_mismatch', 'Record the exact confirmed amount. A zero total is not cash paid.', 409);
        }

        $this->reservations->consume($order);
        $order->forceFill([
            'status' => OrderStatus::Collected,
            'payment_status' => $cashPaisa === 0 ? 'no_payment_due' : 'paid',
            'cash_collected_paisa' => $cashPaisa,
        ])->save();
        $this->event($order, OrderStatus::Collected, 'collected', $pharmacist, null);
        $this->audit->record($pharmacist, 'order_collected', 'order', (string) $order->id, (string) $order->pharmacy_id, [
            'status' => OrderStatus::Collected->value,
            'payment_status' => $order->payment_status,
        ]);

        return $order->load(['items', 'events']);
    }

    private function lockOrder(User $pharmacist, Pharmacy $pharmacy, string $publicId, bool $lockMedicines): Order
    {
        $this->access->lock($pharmacist, $pharmacy);
        $peek = Order::query()
            ->where('public_id', strtolower($publicId))
            ->where('pharmacy_id', $pharmacy->id)
            ->first();

        if (! $peek instanceof Order) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }

        if ($peek->prescription_id !== null) {
            Prescription::query()->whereKey($peek->prescription_id)->lockForUpdate()->first();
            TestingBarrier::wait('after_fulfillment_prescription_lock');
        }

        if ($lockMedicines) {
            $medicineIds = $this->peekMedicineIds($peek);
            sort($medicineIds);

            if ($medicineIds !== []) {
                Medicine::query()->whereIn('id', $medicineIds)->orderBy('id')->lockForUpdate()->get(['id']);
            }

            $this->lockedMedicineIds = $medicineIds;
        }

        $order = Order::query()->whereKey($peek->id)->lockForUpdate()->first();
        TestingBarrier::wait('after_fulfillment_order_lock');

        if (! $order instanceof Order || strtolower((string) $order->pharmacy_id) !== strtolower((string) $pharmacy->id)) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }

        return $order->load('items');
    }

    /**
     * @return list<string>
     */
    private function peekMedicineIds(Order $order): array
    {
        if ($order->request_mode === OrderRequestMode::PrescriptionFullImage) {
            $quote = OrderQuote::query()->where('order_id', $order->id)->where('state', 'accepted')->first();

            if (! $quote instanceof OrderQuote) {
                return [];
            }

            return $quote->lines()->pluck('medicine_id')->map(static fn (mixed $id): string => strtolower((string) $id))->all();
        }

        return $order->items()->pluck('medicine_id')->map(static fn (mixed $id): string => strtolower((string) $id))->all();
    }

    private function lockedPrescription(Order $order): ?Prescription
    {
        if ($order->prescription_id === null) {
            return null;
        }

        $prescription = Prescription::query()->whereKey($order->prescription_id)->first();

        return $prescription instanceof Prescription ? $prescription : null;
    }

    private function assertConfirmablePrescription(Order $order, ?Prescription $prescription, ?OrderQuote $quote): void
    {
        if ($order->prescription_id === null) {
            return;
        }

        if (! $order->prescription_usable || ! $prescription instanceof Prescription) {
            if ($quote instanceof OrderQuote) {
                throw new QuoteNeedsInvalidation((string) $order->id, (string) $quote->id);
            }

            throw new ApiProblem('prescription_unusable', 'This order cannot be confirmed because the prescription is no longer valid.', 409);
        }

        if ($prescription->share_state === 'pending'
            && $prescription->deleted_at === null
            && strtolower((string) $prescription->pharmacy_id) === strtolower((string) $order->pharmacy_id)
            && $prescription->share_mode === $order->prescription_share_mode
            && (int) $prescription->content_version === (int) $order->prescription_content_version
            && (int) $prescription->shared_content_version === (int) $prescription->content_version) {
            throw new ApiProblem('prescription_not_verified', 'A pharmacist still needs to verify this prescription version.', 409);
        }

        if (! $this->prescriptions->fulfillmentShareMatches($order, $prescription)) {
            if ($quote instanceof OrderQuote) {
                throw new QuoteNeedsInvalidation((string) $order->id, (string) $quote->id);
            }

            throw new ApiProblem('prescription_unusable', 'This order cannot be confirmed because the prescription is no longer valid.', 409);
        }
    }

    private function assertFulfillable(Order $order): void
    {
        if ($order->prescription_id === null) {
            return;
        }

        $prescription = $this->lockedPrescription($order);

        if (! $order->prescription_usable || ! $prescription instanceof Prescription || ! $this->prescriptions->fulfillmentShareMatches($order, $prescription)) {
            throw new ApiProblem('prescription_unusable', 'This order cannot be collected because the prescription changed or is no longer shared.', 409);
        }
    }

    /**
     * @return array{total: int, medicine_ids: list<string>, lines: list<array{medicine_id: string, quantity: int, unit_price_paisa: int, order_item_id: ?string, quote_line_id: ?string, generic_name: string, brand_name: ?string, strength: ?string, dosage_form: string, manufacturer: string}>}
     */
    private function plan(Order $order, ?OrderQuote $quote, ?Prescription $prescription): array
    {
        if ($order->request_mode === OrderRequestMode::PrescriptionFullImage) {
            if (! $quote instanceof OrderQuote) {
                throw new ApiProblem('quote_required', 'Enter reviewed lines and wait for the customer to accept the quote.', 409);
            }

            return $this->quotedPlan($order, $quote, $prescription);
        }

        if ($quote instanceof OrderQuote) {
            return $this->quotedPlan($order, $quote, $prescription);
        }

        $lines = [];
        $total = 0;

        foreach ($order->items as $item) {
            $unit = (int) $item->unit_price_paisa;
            $quantity = (int) $item->quantity;
            $total += $unit * $quantity;
            $lines[] = $this->itemLine($item, $unit);
        }

        return [
            'total' => $total,
            'medicine_ids' => array_map(static fn (array $line): string => $line['medicine_id'], $lines),
            'lines' => $lines,
        ];
    }

    /**
     * @return array{total: int, medicine_ids: list<string>, lines: list<array{medicine_id: string, quantity: int, unit_price_paisa: int, order_item_id: ?string, quote_line_id: ?string, generic_name: string, brand_name: ?string, strength: ?string, dosage_form: string, manufacturer: string}>}
     */
    private function quotedPlan(Order $order, OrderQuote $quote, ?Prescription $prescription): array
    {
        $quote->load('lines', 'acceptance');
        $acceptance = $quote->acceptance;

        if ($acceptance === null
            || (int) $acceptance->quote_version !== (int) $quote->version
            || (int) $acceptance->total_paisa !== (int) $quote->total_paisa
            || $acceptance->line_fingerprint !== QuoteService::fingerprint($quote->lines)
            || (int) $acceptance->customer_id !== (int) $order->customer_id) {
            throw new QuoteNeedsInvalidation((string) $order->id, (string) $quote->id);
        }

        if ($quote->expires_at->lte(now())) {
            throw new QuoteNeedsInvalidation((string) $order->id, (string) $quote->id);
        }

        if ($order->prescription_id !== null && (
            (int) $quote->prescription_content_version !== (int) $order->prescription_content_version
            || ! $prescription instanceof Prescription
            || (int) $prescription->content_version !== (int) $order->prescription_content_version
        )) {
            throw new QuoteNeedsInvalidation((string) $order->id, (string) $quote->id);
        }

        if ($order->request_mode !== OrderRequestMode::PrescriptionFullImage) {
            $quoted = [];

            foreach ($quote->lines as $line) {
                $quoted[strtolower((string) $line->medicine_id)] = (int) $line->quantity;
            }

            $submitted = [];

            foreach ($order->items as $item) {
                $submitted[strtolower((string) $item->medicine_id)] = (int) $item->quantity;
            }

            ksort($quoted);
            ksort($submitted);

            if ($quoted !== $submitted) {
                throw new QuoteNeedsInvalidation((string) $order->id, (string) $quote->id);
            }
        }

        $lines = [];
        $total = 0;

        foreach ($quote->lines as $line) {
            $total += (int) $line->line_total_paisa;
            $itemId = null;

            if ($order->request_mode !== OrderRequestMode::PrescriptionFullImage) {
                foreach ($order->items as $item) {
                    if (strtolower((string) $item->medicine_id) === strtolower((string) $line->medicine_id)) {
                        $itemId = (string) $item->id;
                    }
                }
            }

            $lines[] = [
                'medicine_id' => (string) $line->medicine_id,
                'quantity' => (int) $line->quantity,
                'unit_price_paisa' => (int) $line->unit_price_paisa,
                'order_item_id' => $itemId,
                'quote_line_id' => $order->request_mode === OrderRequestMode::PrescriptionFullImage ? (string) $line->id : null,
                'generic_name' => (string) $line->generic_name,
                'brand_name' => $line->brand_name,
                'strength' => $line->strength,
                'dosage_form' => (string) $line->dosage_form,
                'manufacturer' => (string) $line->manufacturer,
            ];
        }

        if ($lines === [] || $total !== (int) $quote->total_paisa) {
            throw new QuoteNeedsInvalidation((string) $order->id, (string) $quote->id);
        }

        return [
            'total' => $total,
            'medicine_ids' => array_map(static fn (array $line): string => $line['medicine_id'], $lines),
            'lines' => $lines,
        ];
    }

    /**
     * @return array{medicine_id: string, quantity: int, unit_price_paisa: int, order_item_id: ?string, quote_line_id: ?string, generic_name: string, brand_name: ?string, strength: ?string, dosage_form: string, manufacturer: string}
     */
    private function itemLine(OrderItem $item, int $unit): array
    {
        return [
            'medicine_id' => (string) $item->medicine_id,
            'quantity' => (int) $item->quantity,
            'unit_price_paisa' => $unit,
            'order_item_id' => (string) $item->id,
            'quote_line_id' => null,
            'generic_name' => (string) $item->generic_name,
            'brand_name' => $item->brand_name,
            'strength' => $item->strength,
            'dosage_form' => (string) $item->dosage_form,
            'manufacturer' => (string) $item->manufacturer,
        ];
    }

    /**
     * @param  array{generic_name: string, brand_name: ?string, strength: ?string, dosage_form: string, manufacturer: string}  $line
     */
    private function sameIdentity(array $line, Medicine $medicine): bool
    {
        return $line['generic_name'] === (string) $medicine->generic_name
            && $line['brand_name'] === $medicine->brand_name
            && $line['strength'] === $medicine->strength
            && $line['dosage_form'] === (string) $medicine->dosage_form
            && $line['manufacturer'] === (string) $medicine->manufacturer
            && $medicine->status === MedicineStatus::Active
            && ! $medicine->controlled_drug
            && $medicine->identityIsComplete();
    }

    private function assertSellable(Medicine $medicine, PharmacyInventory $inventory): void
    {
        $freshAfter = now()->utc()->subHours((int) config('medifind.discovery.freshness_hours'));
        $verified = $inventory->last_verified_at;
        $fresh = $verified instanceof Carbon && $verified->lte(now()) && $verified->gte($freshAfter);
        $stockOk = in_array($inventory->stock_status, [StockStatus::InStock, StockStatus::LowStock], true)
            && $inventory->is_available_for_pickup;

        if (! $fresh || ! $stockOk || $medicine->controlled_drug || $medicine->status !== MedicineStatus::Active) {
            throw new ApiProblem('medicine_unavailable', 'One or more selected medicines are not available for pickup.', 422);
        }
    }

    private function invalidate(QuoteNeedsInvalidation $invalidation): void
    {
        DB::transaction(function () use ($invalidation): void {
            $order = Order::query()->whereKey($invalidation->orderId)->lockForUpdate()->first();

            if (! $order instanceof Order || $order->status !== OrderStatus::PendingPharmacyReview) {
                return;
            }

            $quote = OrderQuote::query()->whereKey($invalidation->quoteId)->where('order_id', $order->id)->lockForUpdate()->first();

            if (! $quote instanceof OrderQuote || $quote->state !== 'accepted') {
                return;
            }

            $quote->state = 'invalid';
            $quote->save();
        });
    }

    private function event(Order $order, OrderStatus $status, string $name, User $actor, ?string $reason): void
    {
        $event = new OrderStatusEvent;
        $event->forceFill([
            'order_id' => $order->id,
            'status' => $status,
            'event' => $name,
            'cause' => null,
            'actor_id' => $actor->id,
            'reason' => $reason,
            'created_at' => now(),
        ])->save();
    }

    private function requiredReason(string $reason): string
    {
        $trimmed = trim($reason);

        if ($trimmed === '' || mb_strlen($trimmed) > 200) {
            throw ValidationException::withMessages(['reason' => ['Enter a reason of 200 characters or fewer.']]);
        }

        return $trimmed;
    }

    private function localInstant(string $value): Carbon
    {
        return Carbon::parse($value, (string) config('medifind.business_timezone'))->utc();
    }

    private function stockConstraint(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'active reservations exceed')
            || str_contains($message, 'active reservations require numeric');
    }
}
