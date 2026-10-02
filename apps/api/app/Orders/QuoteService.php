<?php

declare(strict_types=1);

namespace App\Orders;

use App\Enums\MedicineStatus;
use App\Enums\OrderRequestMode;
use App\Enums\OrderStatus;
use App\Exceptions\ApiProblem;
use App\Models\Medicine;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderQuote;
use App\Models\OrderQuoteAcceptance;
use App\Models\OrderQuoteLine;
use App\Models\Pharmacy;
use App\Models\PharmacyInventory;
use App\Models\User;
use App\Support\TestingBarrier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class QuoteService
{
    public function __construct(
        private readonly FulfillmentAccess $access,
        private readonly ReservationLedger $reservations,
    ) {}

    /**
     * @param  list<array{medicine_id: string, quantity: int, unit_price_paisa: int}>  $lines
     */
    public function propose(User $pharmacist, Pharmacy $pharmacy, string $publicId, array $lines): OrderQuote
    {
        return DB::transaction(function () use ($pharmacist, $pharmacy, $publicId, $lines): OrderQuote {
            $this->access->lock($pharmacist, $pharmacy);
            $peek = Order::query()
                ->where('public_id', strtolower($publicId))
                ->where('pharmacy_id', $pharmacy->id)
                ->first();

            if (! $peek instanceof Order) {
                throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
            }

            $medicineIds = $peek->request_mode === OrderRequestMode::PrescriptionFullImage
                ? array_map(static fn (array $line): string => strtolower($line['medicine_id']), $lines)
                : $peek->items()->pluck('medicine_id')->all();
            $medicineIds = array_values(array_unique($medicineIds));
            sort($medicineIds);

            if ($medicineIds !== []) {
                Medicine::query()->whereIn('id', $medicineIds)->orderBy('id')->lockForUpdate()->get();
            }

            $order = $this->lockOrder($pharmacy, $publicId);
            $this->assertPending($order);
            $prepared = $this->preparedLines($order, $lines);

            if ($order->prescription_usable === false && $order->prescription_id !== null) {
                throw new ApiProblem('prescription_unusable', 'This order cannot be confirmed because the prescription is no longer valid.', 409);
            }

            $medicineIds = array_map(static fn (array $line): string => strtolower($line['medicine_id']), $prepared);
            $inventories = $this->reservations->lockInventories((string) $order->pharmacy_id, $medicineIds);
            TestingBarrier::wait('after_quote_inventory_lock');
            $this->assertCurrentPrices($inventories, $prepared);

            $version = ((int) OrderQuote::query()->where('order_id', $order->id)->max('version')) + 1;
            OrderQuote::query()
                ->where('order_id', $order->id)
                ->whereIn('state', ['open', 'accepted'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->each(function (OrderQuote $quote): void {
                    $quote->state = 'superseded';
                    $quote->save();
                });

            $total = 0;

            foreach ($prepared as $line) {
                $total += $line['line_total_paisa'];
            }

            if ($total > 10000000000) {
                throw new ApiProblem('order_estimate_too_large', 'This quote is too large to store.', 422);
            }

            $quote = new OrderQuote;
            $quote->forceFill([
                'order_id' => $order->id,
                'version' => $version,
                'state' => 'open',
                'total_paisa' => $total,
                'prescription_content_version' => $order->prescription_content_version,
                'created_by' => $pharmacist->id,
                'expires_at' => now()->addSeconds((int) config('medifind.orders.quote_ttl_seconds')),
                'created_at' => now(),
            ])->save();

            $position = 1;

            foreach ($prepared as $line) {
                $row = new OrderQuoteLine;
                $row->forceFill([
                    'quote_id' => $quote->id,
                    'medicine_id' => $line['medicine_id'],
                    'position' => $position,
                    'quantity' => $line['quantity'],
                    'unit_price_paisa' => $line['unit_price_paisa'],
                    'line_total_paisa' => $line['line_total_paisa'],
                    'generic_name' => $line['generic_name'],
                    'brand_name' => $line['brand_name'],
                    'strength' => $line['strength'],
                    'dosage_form' => $line['dosage_form'],
                    'manufacturer' => $line['manufacturer'],
                ])->save();
                $position++;
            }

            return $quote->load('lines');
        });
    }

    public function accept(User $customer, string $publicId, string $quoteId, int $version): Order
    {
        $problem = DB::transaction(function () use ($customer, $publicId, $quoteId, $version): ?string {
            $peek = Order::query()
                ->where('public_id', strtolower($publicId))
                ->where('customer_id', $customer->id)
                ->first();

            if (! $peek instanceof Order) {
                return 'not_found';
            }

            if ($peek->prescription_id !== null) {
                DB::table('prescriptions')->where('id', $peek->prescription_id)->lockForUpdate()->first();
                TestingBarrier::wait('after_acceptance_prescription_lock');
            }

            $order = Order::query()->whereKey($peek->id)->lockForUpdate()->first();

            if (! $order instanceof Order || $order->customer_id !== $customer->id) {
                return 'not_found';
            }

            $quote = OrderQuote::query()->whereKey($quoteId)->where('order_id', $order->id)->lockForUpdate()->first();

            if (! $quote instanceof OrderQuote) {
                return 'not_found';
            }

            if ($quote->state === 'accepted' && (int) $quote->version === $version) {
                return null;
            }

            if ($order->status !== OrderStatus::PendingPharmacyReview || $quote->state !== 'open' || (int) $quote->version !== $version) {
                return 'quote_stale';
            }

            if ($order->prescription_id !== null && ! $order->prescription_usable) {
                $quote->state = 'invalid';
                $quote->save();

                return 'prescription_unusable';
            }

            if ($quote->expires_at->lte(now())) {
                $quote->state = 'invalid';
                $quote->save();

                return 'quote_expired';
            }

            $quote->load('lines');
            $acceptance = new OrderQuoteAcceptance;
            $acceptance->forceFill([
                'quote_id' => $quote->id,
                'order_id' => $order->id,
                'customer_id' => $customer->id,
                'quote_version' => $quote->version,
                'total_paisa' => $quote->total_paisa,
                'prescription_content_version' => $quote->prescription_content_version,
                'line_fingerprint' => self::fingerprint($quote->lines),
                'accepted_at' => now(),
            ])->save();
            $quote->state = 'accepted';
            $quote->save();

            return null;
        });

        if ($problem === 'not_found') {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }

        if ($problem === 'quote_stale') {
            throw new ApiProblem('quote_stale', 'Accept the current quote version.', 409);
        }

        if ($problem === 'quote_expired') {
            throw new ApiProblem('quote_expired', 'This quote has expired. Ask the pharmacy for a new quote.', 409);
        }

        if ($problem === 'prescription_unusable') {
            throw new ApiProblem('prescription_unusable', 'This order cannot be confirmed because the prescription is no longer valid.', 409);
        }

        $order = Order::query()
            ->where('public_id', strtolower($publicId))
            ->where('customer_id', $customer->id)
            ->first();

        if (! $order instanceof Order) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }

        return $order->load(['items', 'events', 'quotes.lines', 'quotes.acceptance']);
    }

    /**
     * @param  Collection<int, OrderQuoteLine>  $lines
     */
    public static function fingerprint(Collection $lines): string
    {
        $parts = [];

        foreach ($lines->sortBy(static fn (OrderQuoteLine $line): string => strtolower((string) $line->medicine_id))->values() as $line) {
            $parts[] = strtolower((string) $line->medicine_id).'|'.(int) $line->quantity.'|'.(int) $line->unit_price_paisa;
        }

        return hash('sha256', implode("\n", $parts));
    }

    private function lockOrder(Pharmacy $pharmacy, string $publicId): Order
    {
        $order = Order::query()
            ->where('public_id', strtolower($publicId))
            ->where('pharmacy_id', $pharmacy->id)
            ->lockForUpdate()
            ->first();

        if (! $order instanceof Order) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }

        return $order->load('items');
    }

    private function assertPending(Order $order): void
    {
        if ($order->status !== OrderStatus::PendingPharmacyReview) {
            throw new ApiProblem('order_not_pending', 'This order is no longer awaiting review.', 409);
        }
    }

    /**
     * @param  Collection<string, PharmacyInventory>  $inventories
     * @param  list<array{medicine_id: string, quantity: int, unit_price_paisa: int, line_total_paisa: int, generic_name: string, brand_name: ?string, strength: ?string, dosage_form: string, manufacturer: string}>  $prepared
     */
    private function assertCurrentPrices(Collection $inventories, array $prepared): void
    {
        foreach ($prepared as $line) {
            $inventory = $inventories->get(strtolower($line['medicine_id']));

            if (! $inventory instanceof PharmacyInventory || (int) $inventory->unit_price_paisa !== (int) $line['unit_price_paisa']) {
                throw ValidationException::withMessages([
                    'lines' => ['Each quoted unit price must match the pharmacy\'s current inventory price.'],
                ]);
            }
        }
    }

    /**
     * @param  list<array{medicine_id: string, quantity: int, unit_price_paisa: int}>  $lines
     * @return list<array{medicine_id: string, quantity: int, unit_price_paisa: int, line_total_paisa: int, generic_name: string, brand_name: ?string, strength: ?string, dosage_form: string, manufacturer: string}>
     */
    private function preparedLines(Order $order, array $lines): array
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => ['Enter at least one reviewed line.']]);
        }

        $normalized = [];

        foreach ($lines as $line) {
            $medicineId = strtolower($line['medicine_id']);
            $quantity = $line['quantity'];
            $unit = $line['unit_price_paisa'];

            if ($quantity < 1 || $quantity > 99 || $unit < 0 || $unit > 1000000000) {
                throw ValidationException::withMessages(['lines' => ['Each line needs a quantity from 1 to 99 and a price of zero or more.']]);
            }

            if (isset($normalized[$medicineId])) {
                throw ValidationException::withMessages(['lines' => ['Each medicine can appear once in a quote.']]);
            }

            $normalized[$medicineId] = ['quantity' => $quantity, 'unit_price_paisa' => $unit];
        }

        $limit = (int) config('medifind.discovery.max_medicines');

        if (count($normalized) > $limit) {
            throw ValidationException::withMessages([
                'lines' => ['A quote can contain at most '.$limit.' medicines.'],
            ]);
        }

        if ($order->request_mode === OrderRequestMode::PrescriptionFullImage) {
            return $this->fullImageLines($order, $normalized);
        }

        return $this->snapshotLines($order, $normalized);
    }

    /**
     * @param  array<string, array{quantity: int, unit_price_paisa: int}>  $normalized
     * @return list<array{medicine_id: string, quantity: int, unit_price_paisa: int, line_total_paisa: int, generic_name: string, brand_name: ?string, strength: ?string, dosage_form: string, manufacturer: string}>
     */
    private function snapshotLines(Order $order, array $normalized): array
    {
        $items = [];

        foreach ($order->items as $item) {
            $items[strtolower((string) $item->medicine_id)] = $item;
        }

        if (array_keys($items) !== array_keys($normalized) && count($items) === count($normalized)) {
            $left = array_keys($items);
            $right = array_keys($normalized);
            sort($left);
            sort($right);

            if ($left !== $right) {
                throw ValidationException::withMessages(['lines' => ['A quote can change the price only. Medicine and quantity stay as the customer requested.']]);
            }
        }

        $left = array_keys($items);
        $right = array_keys($normalized);
        sort($left);
        sort($right);

        if ($left !== $right) {
            throw ValidationException::withMessages(['lines' => ['A quote can change the price only. Medicine and quantity stay as the customer requested.']]);
        }

        $prepared = [];

        foreach ($items as $medicineId => $item) {
            if ((int) $item->quantity !== $normalized[$medicineId]['quantity']) {
                throw ValidationException::withMessages(['lines' => ['A quote can change the price only. Medicine and quantity stay as the customer requested.']]);
            }

            $prepared[] = $this->described($item, $normalized[$medicineId]['unit_price_paisa']);
        }

        return $prepared;
    }

    /**
     * @param  array<string, array{quantity: int, unit_price_paisa: int}>  $normalized
     * @return list<array{medicine_id: string, quantity: int, unit_price_paisa: int, line_total_paisa: int, generic_name: string, brand_name: ?string, strength: ?string, dosage_form: string, manufacturer: string}>
     */
    private function fullImageLines(Order $order, array $normalized): array
    {
        $ids = array_keys($normalized);
        sort($ids);
        $medicines = Medicine::query()->whereIn('id', $ids)->get()->keyBy(
            static fn (Medicine $medicine): string => strtolower((string) $medicine->id),
        );
        $prepared = [];

        foreach ($ids as $medicineId) {
            $medicine = $medicines->get($medicineId);

            if (! $medicine instanceof Medicine || $medicine->status !== MedicineStatus::Active || $medicine->controlled_drug || ! $medicine->identityIsComplete()) {
                throw ValidationException::withMessages(['lines' => ['Choose an active catalog medicine that can be picked up.']]);
            }

            $inventory = PharmacyInventory::query()
                ->where('pharmacy_id', $order->pharmacy_id)
                ->where('medicine_id', $medicine->id)
                ->first();

            if (! $inventory instanceof PharmacyInventory) {
                throw ValidationException::withMessages(['lines' => ['Choose a medicine this pharmacy stocks.']]);
            }

            $unit = $normalized[$medicineId]['unit_price_paisa'];
            $quantity = $normalized[$medicineId]['quantity'];
            $prepared[] = [
                'medicine_id' => (string) $medicine->id,
                'quantity' => $quantity,
                'unit_price_paisa' => $unit,
                'line_total_paisa' => $unit * $quantity,
                'generic_name' => (string) $medicine->generic_name,
                'brand_name' => $medicine->brand_name,
                'strength' => $medicine->strength,
                'dosage_form' => (string) $medicine->dosage_form,
                'manufacturer' => (string) $medicine->manufacturer,
            ];
        }

        return $prepared;
    }

    /**
     * @return array{medicine_id: string, quantity: int, unit_price_paisa: int, line_total_paisa: int, generic_name: string, brand_name: ?string, strength: ?string, dosage_form: string, manufacturer: string}
     */
    private function described(OrderItem $item, int $unit): array
    {
        return [
            'medicine_id' => (string) $item->medicine_id,
            'quantity' => (int) $item->quantity,
            'unit_price_paisa' => $unit,
            'line_total_paisa' => $unit * (int) $item->quantity,
            'generic_name' => (string) $item->generic_name,
            'brand_name' => $item->brand_name,
            'strength' => $item->strength,
            'dosage_form' => (string) $item->dosage_form,
            'manufacturer' => (string) $item->manufacturer,
        ];
    }
}
