<?php

declare(strict_types=1);

namespace App\Orders;

use App\Audit\AuditRecorder;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderStatusEvent;
use App\Models\Prescription;
use App\Models\User;
use App\Support\TestingBarrier;
use Illuminate\Support\Facades\DB;

/**
 * Prescription consequences for an order that is still a request.
 *
 * Lock order, when a prescription is linked: read the order without a lock,
 * lock the prescription, then lock the order, then read both again. Prescription
 * mutations lock the prescription first and orders after that. Do not lock the
 * order and then the prescription.
 *
 * submissionShareMatches() is the pending-order rule: a pending or verified share
 * for the same pharmacy and content version can keep the order usable.
 *
 * prescriptionConfirmationBlock() is the later confirmation rule. Null means
 * the prescription does not block a future confirmation. It is not permission
 * to confirm. Phase 6 must still review stock and price for every order,
 * including orders with no prescription, and must not confirm while this
 * method returns a reason. A pending share returns prescription_not_verified
 * and leaves the order usable. This method never sets an order to confirmed.
 */
final class OrderPrescriptionGuard
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function revokeIfStale(Prescription $prescription, ?User $actor, string $cause): void
    {
        $orders = Order::query()
            ->where('prescription_id', $prescription->id)
            ->where('prescription_usable', true)
            ->whereIn('status', [OrderStatus::PendingPharmacyReview, OrderStatus::Confirmed])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($orders as $order) {
            if ($this->submissionShareMatches($order, $prescription)) {
                continue;
            }

            $this->revoke($order, $actor, $cause);
        }
    }

    /**
     * @return 'missing'|'not_pending'|'prescription_not_verified'|'prescription_unusable'|null
     */
    public function prescriptionConfirmationBlock(Order $order): ?string
    {
        return DB::transaction(function () use ($order): ?string {
            $peek = Order::query()->whereKey($order->id)->first();

            if (! $peek instanceof Order) {
                return 'missing';
            }

            $prescription = null;

            if ($peek->prescription_id !== null) {
                $prescription = Prescription::query()->whereKey($peek->prescription_id)->lockForUpdate()->first();
                TestingBarrier::wait('after_prescription_lock');
            }

            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            if (! $locked instanceof Order) {
                return 'missing';
            }

            if ($locked->status !== OrderStatus::PendingPharmacyReview) {
                return 'not_pending';
            }

            if ($locked->prescription_id === null) {
                return null;
            }

            if ($locked->prescription_id !== $peek->prescription_id || ! $locked->prescription_usable) {
                return 'prescription_unusable';
            }

            if (! $prescription instanceof Prescription || ! $this->submissionShareMatches($locked, $prescription)) {
                $this->revoke($locked, null, 'prescription_stale');

                return 'prescription_unusable';
            }

            if ($prescription->share_state !== 'verified') {
                return 'prescription_not_verified';
            }

            return null;
        });
    }

    private function submissionShareMatches(Order $order, Prescription $prescription): bool
    {
        return $prescription->deleted_at === null
            && in_array($prescription->share_state, ['pending', 'verified'], true)
            && $prescription->pharmacy_id === $order->pharmacy_id
            && $prescription->share_mode === $order->prescription_share_mode
            && $prescription->shared_content_version === $prescription->content_version
            && $prescription->content_version === $order->prescription_content_version;
    }

    private function revoke(Order $order, ?User $actor, string $cause): void
    {
        $order->prescription_usable = false;
        $order->save();

        $event = new OrderStatusEvent;
        $event->forceFill([
            'order_id' => $order->id,
            'status' => $order->status,
            'event' => 'prescription_unusable',
            'cause' => $cause,
            'actor_id' => $actor?->id,
            'reason' => null,
            'created_at' => now(),
        ])->save();

        $this->audit->record($actor, 'order_prescription_unusable', 'order', (string) $order->id, (string) $order->pharmacy_id, [
            'cause' => $cause,
            'status' => $order->status->value,
        ]);
    }
}
