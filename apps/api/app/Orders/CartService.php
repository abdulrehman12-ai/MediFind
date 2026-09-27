<?php

declare(strict_types=1);

namespace App\Orders;

use App\Discovery\PickupEligibility;
use App\Enums\OrderRequestMode;
use App\Exceptions\ApiProblem;
use App\Models\CustomerCart;
use App\Models\CustomerCartItem;
use App\Models\Pharmacy;
use App\Models\Prescription;
use App\Models\User;
use App\Support\TestingBarrier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CartService
{
    public function __construct(
        private readonly PickupEligibility $eligibility,
        private readonly CheckoutPrescription $prescriptions,
    ) {}

    public function show(User $customer): ?CustomerCart
    {
        $cart = CustomerCart::query()->where('customer_id', $customer->id)->first();

        return $cart instanceof CustomerCart ? $cart->load('items') : null;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function replace(User $customer, array $input): CustomerCart
    {
        return DB::transaction(function () use ($customer, $input): CustomerCart {
            $mode = OrderRequestMode::tryFrom((string) ($input['mode'] ?? ''));

            if (! $mode instanceof OrderRequestMode) {
                throw ValidationException::withMessages(['mode' => ['Choose catalog items, selected prescription items, or the full prescription image.']]);
            }

            $pharmacyId = strtolower((string) ($input['pharmacy_id'] ?? ''));

            if (! Str::isUuid($pharmacyId)) {
                throw ValidationException::withMessages(['pharmacy_id' => ['Choose a pharmacy.']]);
            }

            $version = $this->version($input['version'] ?? null);
            $note = $this->note(is_string($input['customer_note'] ?? null) ? $input['customer_note'] : null);
            $items = $this->items($input['items'] ?? null, $mode);
            $prescriptionPublicId = $this->uuidOrNull($input['prescription_id'] ?? null);
            $existing = CustomerCart::query()->where('customer_id', $customer->id)->lockForUpdate()->first();

            if ($existing instanceof CustomerCart) {
                if ($version === null || $version !== $existing->version) {
                    throw new ApiProblem('cart_stale', 'The cart changed. Reload it and try again.', 409);
                }
            } elseif ($version !== null) {
                throw new ApiProblem('cart_stale', 'The cart changed. Reload it and try again.', 409);
            }

            if ($mode !== OrderRequestMode::CatalogItems && $prescriptionPublicId === null) {
                throw ValidationException::withMessages([
                    'prescription_id' => ['A current prescription share is required for this order.'],
                ]);
            }

            $lockedPrescription = $prescriptionPublicId === null
                ? null
                : $this->prescriptions->lockOwned($customer, $prescriptionPublicId);

            if ($lockedPrescription instanceof Prescription) {
                TestingBarrier::wait('after_prescription_lock');
            }

            $cartIds = array_map(static fn (array $item): string => $item['medicine_id'], $items);
            $selectedIds = $lockedPrescription instanceof Prescription
                ? $this->prescriptions->selectedMedicineIds($lockedPrescription, $mode)
                : [];
            $this->lockPharmacy($pharmacyId);
            $this->eligibility->lockRows($pharmacyId, [...$cartIds, ...$selectedIds], $cartIds);
            $prescription = $this->prescriptions->resolve($customer, $pharmacyId, $mode, $prescriptionPublicId, $items);
            $lines = $this->eligibility->lines($pharmacyId, $cartIds);

            if (count($lines) !== count($cartIds)) {
                PickupEligibility::rejectMedicine();
            }

            if ($existing instanceof CustomerCart) {
                $nextVersion = $existing->version + 1;
                $existing->items()->delete();
                $existing->forceFill([
                    'pharmacy_id' => $pharmacyId,
                    'request_mode' => $mode,
                    'prescription_id' => $prescription?->id,
                    'customer_note' => $note,
                    'version' => $nextVersion,
                ])->save();
                $cart = $existing;
                $this->syncSequence((int) $customer->id, $nextVersion);
            } else {
                $nextVersion = $this->allocateVersion((int) $customer->id);
                $race = CustomerCart::query()->where('customer_id', $customer->id)->lockForUpdate()->first();

                if ($race instanceof CustomerCart) {
                    throw new ApiProblem('cart_stale', 'The cart changed. Reload it and try again.', 409);
                }

                $cart = new CustomerCart;
                $cart->forceFill([
                    'customer_id' => $customer->id,
                    'pharmacy_id' => $pharmacyId,
                    'request_mode' => $mode,
                    'prescription_id' => $prescription?->id,
                    'customer_note' => $note,
                    'version' => $nextVersion,
                ])->save();
            }

            foreach ($items as $item) {
                $row = new CustomerCartItem;
                $row->forceFill([
                    'cart_id' => $cart->id,
                    'medicine_id' => $item['medicine_id'],
                    'quantity' => $item['quantity'],
                ])->save();
            }

            return $cart->load('items');
        });
    }

    public function clear(User $customer, int $version): void
    {
        DB::transaction(function () use ($customer, $version): void {
            $cart = CustomerCart::query()->where('customer_id', $customer->id)->lockForUpdate()->first();

            if (! $cart instanceof CustomerCart) {
                return;
            }

            if ($cart->version !== $version) {
                throw new ApiProblem('cart_stale', 'The cart changed. Reload it and try again.', 409);
            }

            $cart->delete();
        });
    }

    private function lockPharmacy(string $pharmacyId): void
    {
        $pharmacy = Pharmacy::query()->whereKey($pharmacyId)->lockForUpdate()->first();

        if (! $pharmacy instanceof Pharmacy) {
            PickupEligibility::rejectPharmacy();
        }

        $this->eligibility->assertPharmacy($pharmacyId);
    }

    private function allocateVersion(int $customerId): int
    {
        $inserted = DB::table('customer_cart_sequences')->insertOrIgnore([
            'customer_id' => $customerId,
            'last_version' => 1,
        ]);
        $row = DB::table('customer_cart_sequences')->where('customer_id', $customerId)->lockForUpdate()->first();
        $current = is_object($row) ? (int) $row->last_version : 1;

        if ($inserted === 1) {
            return 1;
        }

        $version = $current + 1;
        DB::table('customer_cart_sequences')->where('customer_id', $customerId)->update([
            'last_version' => $version,
        ]);

        return $version;
    }

    private function syncSequence(int $customerId, int $version): void
    {
        DB::table('customer_cart_sequences')->insertOrIgnore([
            'customer_id' => $customerId,
            'last_version' => $version,
        ]);
        $row = DB::table('customer_cart_sequences')->where('customer_id', $customerId)->lockForUpdate()->first();
        $current = is_object($row) ? (int) $row->last_version : 0;

        if ($current < $version) {
            DB::table('customer_cart_sequences')->where('customer_id', $customerId)->update([
                'last_version' => $version,
            ]);
        }
    }

    private function version(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (! is_int($value) || $value < 1) {
            throw new ApiProblem('cart_stale', 'The cart changed. Reload it and try again.', 409);
        }

        return $value;
    }

    private function note(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }

        if (mb_strlen($note) > (int) config('medifind.orders.max_note_length')) {
            throw ValidationException::withMessages([
                'customer_note' => ['The note must be 500 characters or fewer.'],
            ]);
        }

        $trimmed = trim($note);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return list<array{medicine_id: string, quantity: int}>
     */
    private function items(mixed $items, OrderRequestMode $mode): array
    {
        if (! is_array($items)) {
            throw ValidationException::withMessages(['items' => ['Add the cart items.']]);
        }

        if ($mode === OrderRequestMode::PrescriptionFullImage) {
            if ($items !== []) {
                throw ValidationException::withMessages(['items' => ['A full-image request has no catalog lines.']]);
            }

            return [];
        }

        $limit = (int) config('medifind.discovery.max_medicines');
        $quantityLimit = (int) config('medifind.orders.max_quantity');

        if ($items === []) {
            throw ValidationException::withMessages(['items' => ['Add at least one medicine.']]);
        }

        if (count($items) > $limit) {
            throw ValidationException::withMessages(['items' => ['A cart can contain at most '.$limit.' medicines.']]);
        }

        $lines = [];
        $seen = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages(['items' => ['Each item needs a medicine and a quantity.']]);
            }

            $id = strtolower((string) ($item['medicine_id'] ?? ''));
            $quantity = $item['quantity'] ?? null;
            $normalizedQuantity = is_int($quantity) ? $quantity : null;

            if ($normalizedQuantity === null && is_string($quantity) && preg_match('/^[1-9][0-9]*$/', $quantity) === 1) {
                $normalizedQuantity = (int) $quantity;
            }

            if (! Str::isUuid($id) || $normalizedQuantity === null || $normalizedQuantity < 1 || $normalizedQuantity > $quantityLimit) {
                throw ValidationException::withMessages(['items' => ['Each item needs a medicine and a quantity from 1 to '.$quantityLimit.'.']]);
            }

            if (isset($seen[$id])) {
                throw ValidationException::withMessages(['items' => ['Each medicine can appear once.']]);
            }

            $seen[$id] = true;
            $lines[] = ['medicine_id' => $id, 'quantity' => $normalizedQuantity];
        }

        return $lines;
    }

    private function uuidOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = strtolower((string) $value);

        return Str::isUuid($id) ? $id : null;
    }
}
