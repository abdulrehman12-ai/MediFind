<?php

declare(strict_types=1);

namespace App\Orders;

use App\Enums\OrderRequestMode;
use App\Exceptions\ApiProblem;
use App\Models\Medicine;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\User;
use App\Prescriptions\SelectionIdentity;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Prescription checks for a pending pickup order.
 *
 * Lock the prescription with lockOwned before locking medicines or inventory.
 * resolve() repeats the selection-identity check after those locks are held.
 * A pending or verified share can create a pending order. Confirmation is a
 * separate decision on OrderPrescriptionGuard.
 */
final class CheckoutPrescription
{
    public function __construct(private readonly SelectionIdentity $selection) {}

    public function lockOwned(User $customer, string $publicId): Prescription
    {
        $prescription = Prescription::query()
            ->where('public_id', strtolower($publicId))
            ->lockForUpdate()
            ->first();

        if (! $prescription instanceof Prescription || $prescription->customer_id !== $customer->id || $prescription->deleted_at !== null) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }

        return $prescription;
    }

    public function lockOwnedById(User $customer, string $id): Prescription
    {
        $prescription = Prescription::query()->whereKey($id)->lockForUpdate()->first();

        if (! $prescription instanceof Prescription || $prescription->customer_id !== $customer->id || $prescription->deleted_at !== null) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }

        return $prescription;
    }

    /**
     * Medicine ids named by the current explicit selection.
     * Full-image carts and full-image shares do not contribute lines.
     *
     * @return list<string>
     */
    public function selectedMedicineIds(Prescription $prescription, OrderRequestMode $mode): array
    {
        if ($mode === OrderRequestMode::PrescriptionFullImage || ($mode === OrderRequestMode::CatalogItems && $prescription->share_mode !== 'selected_items')) {
            return [];
        }

        $ids = [];

        foreach ($this->selectedLines($prescription) as $row) {
            if (is_string($row->selected_medicine_id)) {
                $ids[] = strtolower($row->selected_medicine_id);
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * @param  list<array{medicine_id: string, quantity: int}>  $items
     */
    public function resolve(
        User $customer,
        string $pharmacyId,
        OrderRequestMode $mode,
        ?string $prescriptionPublicId,
        array $items,
    ): ?Prescription {
        $cartIds = array_map(static fn (array $item): string => $item['medicine_id'], $items);
        sort($cartIds);
        $cartMedicines = $cartIds === []
            ? collect()
            : Medicine::query()->whereIn('id', $cartIds)->orderBy('id')->lockForUpdate()->get();
        $requiresPrescription = false;

        foreach ($cartMedicines as $medicine) {
            if ($medicine->prescription_required) {
                $requiresPrescription = true;
                break;
            }
        }

        if ($mode === OrderRequestMode::CatalogItems && ! $requiresPrescription) {
            if ($prescriptionPublicId !== null) {
                throw ValidationException::withMessages([
                    'prescription_id' => ['This catalog cart does not use a prescription.'],
                ]);
            }

            return null;
        }

        if ($prescriptionPublicId === null || ! Str::isUuid($prescriptionPublicId)) {
            throw ValidationException::withMessages([
                'prescription_id' => ['A current prescription share is required for this order.'],
            ]);
        }

        $prescription = $this->lockOwned($customer, $prescriptionPublicId);
        $expectedShare = match ($mode) {
            OrderRequestMode::PrescriptionSelectedItems => 'selected_items',
            OrderRequestMode::PrescriptionFullImage => 'full_image',
            OrderRequestMode::CatalogItems => $prescription->share_mode,
        };

        if (! in_array($prescription->share_state, ['pending', 'verified'], true)
            || $prescription->pharmacy_id !== $pharmacyId
            || $prescription->share_mode !== $expectedShare
            || $prescription->shared_content_version !== $prescription->content_version
            || ! in_array($prescription->share_mode, ['selected_items', 'full_image'], true)) {
            throw new ApiProblem('prescription_unavailable', 'This prescription cannot be used for this order.', 409);
        }

        if ($mode === OrderRequestMode::PrescriptionFullImage) {
            return $prescription;
        }

        if ($mode === OrderRequestMode::PrescriptionSelectedItems || $prescription->share_mode === 'selected_items') {
            $selected = $this->assertSelectedIdentity($prescription);
        } else {
            $selected = [];
        }

        if ($mode === OrderRequestMode::PrescriptionSelectedItems) {
            $requested = $cartIds;

            if ($requested !== $selected) {
                throw new ApiProblem('prescription_unavailable', 'This prescription cannot be used for this order.', 409);
            }

            return $prescription;
        }

        if ($prescription->share_mode === 'selected_items') {
            foreach ($cartMedicines as $medicine) {
                if ($medicine->prescription_required && ! in_array(strtolower((string) $medicine->id), $selected, true)) {
                    throw new ApiProblem('prescription_unavailable', 'This prescription cannot be used for this order.', 409);
                }
            }
        }

        return $prescription;
    }

    /**
     * @return list<string>
     */
    private function assertSelectedIdentity(Prescription $prescription): array
    {
        $rows = $this->selectedLines($prescription);
        $pairs = [];
        $ids = [];

        foreach ($rows as $row) {
            if (! is_string($row->selected_medicine_id)) {
                throw new ApiProblem('prescription_unavailable', 'This prescription cannot be used for this order.', 409);
            }

            $id = strtolower($row->selected_medicine_id);
            $ids[] = $id;
            $pairs[] = ['item' => $row, 'id' => $id];
        }

        $unique = array_values(array_unique($ids));
        sort($unique);
        $medicines = $unique === []
            ? collect()
            : Medicine::query()->whereIn('id', $unique)->orderBy('id')->lockForUpdate()->get();
        $byId = [];

        foreach ($medicines as $medicine) {
            $byId[strtolower((string) $medicine->id)] = $medicine;
        }

        foreach ($pairs as $pair) {
            $item = $pair['item'];
            $medicine = $byId[$pair['id']] ?? null;

            if (! $medicine instanceof Medicine || ! $this->selection->matches($item, $medicine)) {
                throw new ApiProblem('prescription_unavailable', 'This prescription cannot be used for this order.', 409);
            }
        }

        return $unique;
    }

    /**
     * @return list<PrescriptionItem>
     */
    private function selectedLines(Prescription $prescription): array
    {
        $rows = [];

        foreach (PrescriptionItem::query()
            ->where('prescription_id', $prescription->id)
            ->where('extraction_version', $prescription->extraction_version)
            ->where('customer_selected', true)
            ->orderBy('id')
            ->lockForUpdate()
            ->get() as $row) {
            $rows[] = $row;
        }

        return $rows;
    }
}
