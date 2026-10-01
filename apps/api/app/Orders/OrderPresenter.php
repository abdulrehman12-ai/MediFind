<?php

declare(strict_types=1);

namespace App\Orders;

use App\Discovery\PickupEligibility;
use App\Enums\OrderStatus;
use App\Models\CustomerCart;
use App\Models\Medicine;
use App\Models\Order;
use App\Models\OrderQuote;
use App\Models\OrderStatusEvent;
use App\Models\Prescription;
use Illuminate\Support\Carbon;

final class OrderPresenter
{
    public function __construct(private readonly PickupEligibility $eligibility) {}

    /**
     * @return array<string, mixed>|null
     */
    public function cart(?CustomerCart $cart): ?array
    {
        if (! $cart instanceof CustomerCart) {
            return null;
        }

        $cart->loadMissing('items');
        $ids = [];

        foreach ($cart->items as $item) {
            $ids[] = strtolower((string) $item->medicine_id);
        }

        $lines = $this->eligibility->lines(strtolower((string) $cart->pharmacy_id), $ids);
        $medicines = Medicine::query()->whereIn('id', $ids)->get()->keyBy(
            static fn (Medicine $medicine): string => strtolower((string) $medicine->id),
        );
        $items = [];

        foreach ($cart->items as $item) {
            $id = strtolower((string) $item->medicine_id);
            $medicine = $medicines->get($id);
            $line = $lines[$id] ?? null;
            $items[] = [
                'medicine_id' => $id,
                'quantity' => (int) $item->quantity,
                'generic_name' => $medicine instanceof Medicine ? $this->text($medicine->getAttribute('generic_name')) : null,
                'brand_name' => $medicine instanceof Medicine ? $this->text($medicine->getAttribute('brand_name')) : null,
                'strength' => $medicine instanceof Medicine ? $this->text($medicine->getAttribute('strength')) : null,
                'dosage_form' => $medicine instanceof Medicine ? $this->text($medicine->getAttribute('dosage_form')) : null,
                'manufacturer' => $medicine instanceof Medicine ? $this->text($medicine->getAttribute('manufacturer')) : null,
                'eligible' => $line !== null,
                'unit_price_paisa' => $line['unit_price_paisa'] ?? null,
                'price_is_estimate' => true,
            ];
        }

        return [
            'version' => (int) $cart->version,
            'pharmacy_id' => strtolower((string) $cart->pharmacy_id),
            'request_mode' => $cart->request_mode->value,
            'prescription' => $this->prescriptionSummary($cart->prescription_id),
            'customer_note' => $cart->customer_note,
            'amounts_are_estimates' => true,
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function order(Order $order): array
    {
        $order->loadMissing(['items', 'events', 'quotes.lines', 'quotes.acceptance']);
        $items = [];

        foreach ($order->items as $item) {
            $items[] = [
                'medicine_id' => strtolower((string) $item->medicine_id),
                'generic_name' => $item->generic_name,
                'brand_name' => $item->brand_name,
                'strength' => $item->strength,
                'dosage_form' => $item->dosage_form,
                'manufacturer' => $item->manufacturer,
                'prescription_required' => (bool) $item->prescription_required,
                'quantity' => (int) $item->quantity,
                'unit_price_paisa' => (int) $item->unit_price_paisa,
                'line_estimate_paisa' => (int) $item->line_estimate_paisa,
                'price_verified_at' => $this->timestamp($item->inventory_verified_at),
            ];
        }

        $timeline = [];

        foreach ($order->events as $event) {
            $entry = [
                'status' => $event->status->value,
                'event' => $event->event,
                'at' => $this->timestamp($event->created_at),
                'actor' => $this->actor($order, $event),
            ];

            if ($event->event === 'prescription_unusable') {
                $entry['cause'] = $event->cause;
            }

            if (in_array($event->event, ['cancelled', 'rejected', 'not_collected'], true)) {
                $entry['reason'] = $event->reason;
            }

            $timeline[] = $entry;
        }

        $status = $order->status;
        $notices = OrderLifecycle::notices($status, $order->request_mode);

        if ($status === OrderStatus::PendingPharmacyReview && ! $order->prescription_usable) {
            $notices[0] = 'This order cannot be confirmed because the prescription is no longer valid. Place a new order.';
        }

        if ($status === OrderStatus::Collected && $order->payment_status === 'no_payment_due') {
            $notices = ['This order was collected. No payment was due.'];
        }

        $quote = $this->currentQuote($order);

        return [
            'id' => $order->public_id,
            'reference' => $order->reference,
            'pharmacy_id' => strtolower((string) $order->pharmacy_id),
            'status' => $status->value,
            'status_text' => $notices[0],
            'request_mode' => $order->request_mode->value,
            'prescription_id' => $order->prescription_public_id,
            'prescription_usable' => (bool) $order->prescription_usable,
            'customer_note' => $order->customer_note,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'amounts_are_estimates' => $order->confirmed_total_paisa === null,
            'estimate_total_paisa' => $order->estimate_total_paisa,
            'confirmed_total_paisa' => $order->confirmed_total_paisa,
            'pickup_window' => $order->pickup_window_starts_at === null ? null : [
                'starts_at' => $this->timestamp($order->pickup_window_starts_at),
                'ends_at' => $this->timestamp($order->pickup_window_ends_at),
                'collect_until' => $this->timestamp($order->collect_until),
                'grace_seconds' => (int) $order->pickup_grace_seconds,
            ],
            'cash_collected_paisa' => $order->cash_collected_paisa,
            'notices' => $notices,
            'items' => $items,
            'reviewed_lines' => $quote instanceof OrderQuote ? $this->reviewedLines($quote) : $this->confirmedRequestLines($order),
            'quote' => $quote instanceof OrderQuote ? $this->quotePayload($quote) : null,
            'timeline' => $timeline,
            'created_at' => $this->timestamp($order->created_at),
        ];
    }

    private function currentQuote(Order $order): ?OrderQuote
    {
        $selected = null;

        foreach ($order->quotes as $quote) {
            if (! in_array($quote->state, ['open', 'accepted'], true)) {
                continue;
            }

            if (! $selected instanceof OrderQuote || (int) $quote->version > (int) $selected->version) {
                $selected = $quote;
            }
        }

        return $selected;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reviewedLines(OrderQuote $quote): array
    {
        $lines = [];

        foreach ($quote->lines as $line) {
            $lines[] = [
                'medicine_id' => strtolower((string) $line->medicine_id),
                'generic_name' => $line->generic_name,
                'brand_name' => $line->brand_name,
                'strength' => $line->strength,
                'dosage_form' => $line->dosage_form,
                'manufacturer' => $line->manufacturer,
                'quantity' => (int) $line->quantity,
                'unit_price_paisa' => (int) $line->unit_price_paisa,
                'line_total_paisa' => (int) $line->line_total_paisa,
            ];
        }

        return $lines;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function confirmedRequestLines(Order $order): array
    {
        if ($order->confirmed_total_paisa === null) {
            return [];
        }

        $lines = [];

        foreach ($order->items as $item) {
            $lines[] = [
                'medicine_id' => strtolower((string) $item->medicine_id),
                'generic_name' => $item->generic_name,
                'brand_name' => $item->brand_name,
                'strength' => $item->strength,
                'dosage_form' => $item->dosage_form,
                'manufacturer' => $item->manufacturer,
                'quantity' => (int) $item->quantity,
                'unit_price_paisa' => (int) $item->unit_price_paisa,
                'line_total_paisa' => (int) $item->line_estimate_paisa,
            ];
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    private function quotePayload(OrderQuote $quote): array
    {
        return [
            'id' => (string) $quote->id,
            'version' => (int) $quote->version,
            'state' => $quote->state,
            'total_paisa' => (int) $quote->total_paisa,
            'expires_at' => $this->timestamp($quote->expires_at),
            'accepted' => $quote->state === 'accepted',
            'lines' => $this->reviewedLines($quote),
        ];
    }

    /**
     * @return array{id: string, content_version: int, share_mode: ?string, share_state: string}|null
     */
    private function prescriptionSummary(?string $prescriptionId): ?array
    {
        if ($prescriptionId === null) {
            return null;
        }

        $prescription = Prescription::query()->whereKey($prescriptionId)->first([
            'id',
            'public_id',
            'content_version',
            'share_mode',
            'share_state',
            'deleted_at',
        ]);

        if (! $prescription instanceof Prescription) {
            return null;
        }

        if ($prescription->deleted_at !== null) {
            return [
                'id' => $prescription->public_id,
                'content_version' => (int) $prescription->content_version,
                'share_mode' => null,
                'share_state' => 'deleted',
            ];
        }

        return [
            'id' => $prescription->public_id,
            'content_version' => (int) $prescription->content_version,
            'share_mode' => $prescription->share_mode,
            'share_state' => $prescription->share_state,
        ];
    }

    private function actor(Order $order, OrderStatusEvent $event): string
    {
        if ($event->actor_id === null) {
            return 'system';
        }

        if ((int) $event->actor_id === (int) $order->customer_id) {
            return 'customer';
        }

        return 'pharmacy';
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function timestamp(mixed $value): string
    {
        $time = $value instanceof Carbon ? $value->copy()->utc() : Carbon::parse((string) $value)->utc();

        return $time->format('Y-m-d\TH:i:s.u\Z');
    }
}
