<?php

declare(strict_types=1);

namespace App\Orders;

use App\Audit\AuditRecorder;
use App\Discovery\PickupEligibility;
use App\Enums\OrderRequestMode;
use App\Enums\OrderStatus;
use App\Exceptions\ApiProblem;
use App\Models\CustomerCart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusEvent;
use App\Models\OrderSubmission;
use App\Models\Pharmacy;
use App\Models\Prescription;
use App\Models\User;
use App\Support\TestingBarrier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OrderService
{
    private const MAX_UNIT_PAISA = 1000000000;

    private const MAX_ESTIMATE_PAISA = 10000000000;

    public function __construct(
        private readonly PickupEligibility $eligibility,
        private readonly CheckoutPrescription $prescriptions,
        private readonly OrderHistory $history,
        private readonly AuditRecorder $audit,
        private readonly ReservationLedger $reservations,
    ) {}

    /**
     * @return array{order: Order, replayed: bool}
     */
    public function submit(User $customer, int $cartVersion, string $idempotencyKey): array
    {
        try {
            return DB::transaction(fn (): array => $this->once($customer, $cartVersion, $idempotencyKey));
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), 'order_submissions_customer_key_unique')) {
                throw $exception;
            }
        }

        return DB::transaction(fn (): array => $this->once($customer, $cartVersion, $idempotencyKey));
    }

    /**
     * @return array{rows: list<Order>, has_more: bool, next_cursor: string|null}
     */
    public function list(User $customer, mixed $cursor): array
    {
        return $this->history->listOwned($customer, $cursor);
    }

    public function show(User $customer, string $publicId): Order
    {
        return $this->owned($customer, $publicId);
    }

    public function cancel(User $customer, string $publicId, ?string $reason): Order
    {
        return DB::transaction(function () use ($customer, $publicId, $reason): Order {
            $order = Order::query()
                ->where('public_id', strtolower($publicId))
                ->where('customer_id', $customer->id)
                ->lockForUpdate()
                ->first();

            if (! $order instanceof Order) {
                throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
            }

            TestingBarrier::wait('after_cancel_order_lock');

            if ($order->status === OrderStatus::Cancelled) {
                return $order->load(['items', 'events']);
            }

            if (! OrderLifecycle::customerMayCancel($order->status)) {
                throw new ApiProblem('order_not_cancellable', 'This order can no longer be cancelled.', 409);
            }

            if ($order->status === OrderStatus::Confirmed) {
                $this->reservations->releaseOrders([(string) $order->id]);
            }

            $order->status = OrderStatus::Cancelled;
            $order->save();

            $event = new OrderStatusEvent;
            $event->forceFill([
                'order_id' => $order->id,
                'status' => OrderStatus::Cancelled,
                'event' => 'cancelled',
                'cause' => null,
                'actor_id' => $customer->id,
                'reason' => $this->reason($reason),
                'created_at' => now(),
            ])->save();
            $this->audit->record($customer, 'order_cancelled', 'order', (string) $order->id, (string) $order->pharmacy_id, [
                'status' => OrderStatus::Cancelled->value,
            ]);

            return $order->load(['items', 'events']);
        });
    }

    /**
     * @return array{order: Order, replayed: bool}
     */
    private function once(User $customer, int $cartVersion, string $idempotencyKey): array
    {
        $existing = $this->lockedSubmission($customer, $idempotencyKey);
        $cart = CustomerCart::query()->where('customer_id', $customer->id)->lockForUpdate()->first();
        $existing = $this->lockedSubmission($customer, $idempotencyKey) ?? $existing;

        if ($existing instanceof OrderSubmission) {
            return $this->replay($existing, $cartVersion);
        }

        if (! $cart instanceof CustomerCart || $cart->version !== $cartVersion) {
            throw new ApiProblem('cart_stale', 'The cart changed. Reload it and try again.', 409);
        }

        $cart->load('items');
        $mode = $cart->request_mode;
        $pharmacyId = strtolower((string) $cart->pharmacy_id);
        $items = [];

        foreach ($cart->items as $item) {
            $items[] = [
                'medicine_id' => strtolower((string) $item->medicine_id),
                'quantity' => (int) $item->quantity,
            ];
        }

        $lockedPrescription = $cart->prescription_id === null
            ? null
            : $this->prescriptions->lockOwnedById($customer, (string) $cart->prescription_id);

        if ($lockedPrescription instanceof Prescription) {
            TestingBarrier::wait('after_prescription_lock');
        }

        $cartIds = array_map(static fn (array $item): string => $item['medicine_id'], $items);
        $selectedIds = $lockedPrescription instanceof Prescription
            ? $this->prescriptions->selectedMedicineIds($lockedPrescription, $mode)
            : [];
        $this->lockPharmacy($pharmacyId);
        $this->eligibility->lockRows($pharmacyId, [...$cartIds, ...$selectedIds], $cartIds);
        $prescription = $this->prescriptions->resolve(
            $customer,
            $pharmacyId,
            $mode,
            $lockedPrescription instanceof Prescription ? strtolower((string) $lockedPrescription->public_id) : null,
            $items,
        );
        $lines = $this->eligibility->lines($pharmacyId, $cartIds);

        if (count($lines) !== count($cartIds)) {
            PickupEligibility::rejectMedicine();
        }
        $estimate = $mode === OrderRequestMode::PrescriptionFullImage ? null : $this->estimate($items, $lines);
        $fingerprint = CheckoutFingerprint::hash(
            $cart->version,
            $pharmacyId,
            $mode->value,
            $cart->customer_note,
            $prescription?->public_id,
            $prescription?->content_version,
            $prescription?->share_mode,
            $items,
        );

        $order = new Order;
        $order->forceFill([
            'public_id' => (string) Str::uuid(),
            'reference' => $this->reference(),
            'customer_id' => $customer->id,
            'pharmacy_id' => $pharmacyId,
            'request_mode' => $mode,
            'prescription_id' => $prescription?->id,
            'prescription_public_id' => $prescription?->public_id,
            'prescription_content_version' => $prescription?->content_version,
            'prescription_share_mode' => $prescription?->share_mode,
            'prescription_usable' => true,
            'customer_note' => $cart->customer_note,
            'status' => OrderStatus::PendingPharmacyReview,
            'payment_method' => 'cash_on_pickup',
            'payment_status' => 'unpaid',
            'estimate_total_paisa' => $estimate,
        ])->save();

        foreach ($items as $item) {
            $line = $lines[$item['medicine_id']];
            $row = new OrderItem;
            $row->forceFill([
                'order_id' => $order->id,
                'medicine_id' => $line['medicine_id'],
                'generic_name' => $line['generic_name'],
                'brand_name' => $line['brand_name'],
                'strength' => $line['strength'],
                'dosage_form' => $line['dosage_form'],
                'manufacturer' => $line['manufacturer'],
                'prescription_required' => $line['prescription_required'],
                'quantity' => $item['quantity'],
                'unit_price_paisa' => $line['unit_price_paisa'],
                'line_estimate_paisa' => $line['unit_price_paisa'] * $item['quantity'],
                'inventory_id' => $line['inventory_id'],
                'inventory_version' => $line['inventory_version'],
                'inventory_verified_at' => $line['verified_at'],
            ])->save();
        }

        DB::statement('SET CONSTRAINTS medifind_order_items_present IMMEDIATE');

        try {
            $submitted = new OrderStatusEvent;
            $submitted->forceFill([
                'order_id' => $order->id,
                'status' => OrderStatus::PendingPharmacyReview,
                'event' => 'submitted',
                'cause' => null,
                'actor_id' => $customer->id,
                'reason' => null,
                'created_at' => now(),
            ])->save();

            $submission = new OrderSubmission;
            $submission->forceFill([
                'customer_id' => $customer->id,
                'key_hash' => hash('sha256', $idempotencyKey),
                'fingerprint' => $fingerprint,
                'cart_version' => $cart->version,
                'order_id' => $order->id,
                'created_at' => now(),
            ])->save();

            $this->audit->record($customer, 'order_submitted', 'order', (string) $order->id, $pharmacyId, [
                'request_mode' => $mode->value,
                'item_count' => count($items),
                'has_estimate' => $estimate !== null,
            ]);
            $cart->delete();
            $this->hook();

            return ['order' => $order->load(['items', 'events']), 'replayed' => false];
        } finally {
            DB::statement('SET CONSTRAINTS medifind_order_items_present DEFERRED');
        }
    }

    /**
     * @return array{order: Order, replayed: bool}
     */
    private function replay(OrderSubmission $existing, int $cartVersion): array
    {
        if ($existing->cart_version !== $cartVersion) {
            throw new ApiProblem('idempotency_conflict', 'This idempotency key was already used for a different checkout.', 409);
        }

        $order = Order::query()->whereKey($existing->order_id)->first();

        if (! $order instanceof Order || $order->customer_id !== $existing->customer_id) {
            throw new ApiProblem('request_not_completed', 'Submit the order again with the same idempotency key.', 503);
        }

        return ['order' => $order->load(['items', 'events']), 'replayed' => true];
    }

    private function lockedSubmission(User $customer, string $idempotencyKey): ?OrderSubmission
    {
        $row = OrderSubmission::query()
            ->where('customer_id', $customer->id)
            ->where('key_hash', hash('sha256', $idempotencyKey))
            ->lockForUpdate()
            ->first();

        return $row instanceof OrderSubmission ? $row : null;
    }

    private function lockPharmacy(string $pharmacyId): void
    {
        $pharmacy = Pharmacy::query()->whereKey($pharmacyId)->lockForUpdate()->first();

        if (! $pharmacy instanceof Pharmacy) {
            PickupEligibility::rejectPharmacy();
        }

        $this->eligibility->assertPharmacy($pharmacyId);
    }

    /**
     * @param  list<array{medicine_id: string, quantity: int}>  $items
     * @param  array<string, array{unit_price_paisa: int}>  $lines
     */
    private function estimate(array $items, array $lines): int
    {
        $total = 0;

        foreach ($items as $item) {
            $unit = $lines[$item['medicine_id']]['unit_price_paisa'];
            $quantity = $item['quantity'];

            if ($unit < 0 || $unit > self::MAX_UNIT_PAISA || $quantity < 1 || $unit > intdiv(PHP_INT_MAX, $quantity)) {
                PickupEligibility::rejectMedicine();
            }

            $line = $unit * $quantity;

            if ($total > PHP_INT_MAX - $line) {
                PickupEligibility::rejectMedicine();
            }

            $total += $line;
        }

        if ($total > self::MAX_ESTIMATE_PAISA) {
            throw new ApiProblem('order_estimate_too_large', 'This order estimate is too large to submit.', 422);
        }

        return $total;
    }

    private function reference(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $bytes = random_bytes(8);
            $value = 'MF';

            for ($index = 0; $index < 8; $index++) {
                $value .= $alphabet[ord($bytes[$index]) % 32];
            }

            if (! Order::query()->where('reference', $value)->exists()) {
                return $value;
            }
        }

        throw new ApiProblem('request_not_completed', 'Submit the order again with the same idempotency key.', 503);
    }

    private function reason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        if (mb_strlen($reason) > (int) config('medifind.orders.max_cancel_reason_length')) {
            throw ValidationException::withMessages([
                'reason' => ['The reason must be 200 characters or fewer.'],
            ]);
        }

        $trimmed = trim($reason);

        return $trimmed === '' ? null : $trimmed;
    }

    private function owned(User $customer, string $publicId): Order
    {
        $order = Order::query()
            ->where('public_id', strtolower($publicId))
            ->where('customer_id', $customer->id)
            ->first();

        if (! $order instanceof Order) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }

        return $order->load(['items', 'events']);
    }

    private function hook(): void
    {
        $hook = config('medifind.orders.testing_hooks.before_submit_commit');

        if (is_callable($hook)) {
            $hook();
        }
    }
}
