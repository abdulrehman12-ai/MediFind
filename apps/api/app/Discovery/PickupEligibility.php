<?php

declare(strict_types=1);

namespace App\Discovery;

use App\Enums\MedicineStatus;
use App\Exceptions\ApiProblem;
use App\Models\Medicine;
use App\Models\PharmacyInventory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class PickupEligibility
{
    public const PHARMACY_SQL = <<<'SQL'
cities.country_code = 'PK'
  AND cities.name = 'Faisalabad'
  AND cities.is_active IS TRUE
  AND pharmacies.status = 'approved'
  AND pharmacies.license_status = 'verified'
  AND pharmacies.location_review_status = 'approved'
  AND pharmacies.location IS NOT NULL
SQL;

    public const INVENTORY_SQL = <<<'SQL'
medicines.status = 'active'
  AND medicines.controlled_drug IS FALSE
  AND pharmacy_inventory.is_available_for_pickup IS TRUE
  AND pharmacy_inventory.stock_status IN ('in_stock', 'low_stock')
  AND pharmacy_inventory.last_verified_at IS NOT NULL
  AND pharmacy_inventory.last_verified_at <= ?
  AND pharmacy_inventory.last_verified_at >= ?
  AND (
        (
            pharmacy_inventory.stock_status = 'in_stock'
            AND (
                pharmacy_inventory.quantity_on_hand IS NULL
                OR pharmacy_inventory.quantity_on_hand > 0
            )
        ) OR (
            pharmacy_inventory.stock_status = 'low_stock'
            AND pharmacy_inventory.quantity_on_hand > 0
        )
  )
SQL;

    public static function medicineCanBePickup(Medicine $medicine): bool
    {
        return $medicine->status === MedicineStatus::Active
            && ! $medicine->controlled_drug
            && $medicine->identityIsComplete();
    }

    public static function rejectMedicine(): never
    {
        throw new ApiProblem('medicine_unavailable', 'One or more selected medicines are not available for pickup.', 422);
    }

    public static function rejectPharmacy(): never
    {
        throw new ApiProblem('pharmacy_unavailable', 'That pharmacy cannot accept a pickup order.', 422);
    }

    /**
     * @param  list<string>  $medicineIds
     * @param  list<string>  $inventoryMedicineIds
     */
    public function lockRows(string $pharmacyId, array $medicineIds, array $inventoryMedicineIds): void
    {
        $medicineIds = $this->sortedIds($medicineIds);

        if ($medicineIds !== []) {
            Medicine::query()->whereIn('id', $medicineIds)->orderBy('id')->lockForUpdate()->get(['id']);
        }

        $inventoryMedicineIds = $this->sortedIds($inventoryMedicineIds);

        if ($inventoryMedicineIds === []) {
            return;
        }

        PharmacyInventory::query()
            ->where('pharmacy_id', $pharmacyId)
            ->whereIn('medicine_id', $inventoryMedicineIds)
            ->orderBy('medicine_id')
            ->lockForUpdate()
            ->get(['id']);
    }

    /**
     * @param  list<string>  $medicineIds
     */
    public function lockCatalog(string $pharmacyId, array $medicineIds): void
    {
        $this->lockRows($pharmacyId, $medicineIds, $medicineIds);
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function sortedIds(array $ids): array
    {
        $unique = array_values(array_unique($ids));
        sort($unique);

        return $unique;
    }

    public function assertPharmacy(string $pharmacyId): void
    {
        $pharmacySql = self::PHARMACY_SQL;
        $row = DB::selectOne(<<<SQL
SELECT pharmacies.id
FROM pharmacies
INNER JOIN cities ON cities.id = pharmacies.city_id
WHERE pharmacies.id = ?
  AND {$pharmacySql}
SQL, [$pharmacyId]);

        if ($row === null) {
            self::rejectPharmacy();
        }
    }

    /**
     * @param  list<string>  $medicineIds
     * @return array<string, array{medicine_id: string, generic_name: string, brand_name: ?string, strength: ?string, dosage_form: string, manufacturer: string, prescription_required: bool, inventory_id: string, inventory_version: int, unit_price_paisa: int, verified_at: string}>
     */
    public function lines(string $pharmacyId, array $medicineIds, ?Carbon $asOf = null): array
    {
        if ($medicineIds === []) {
            return [];
        }

        $asOf ??= now()->utc();
        $freshAfter = $asOf->copy()->subHours((int) config('medifind.discovery.freshness_hours'));
        $placeholders = implode(', ', array_fill(0, count($medicineIds), '?'));
        $pharmacySql = self::PHARMACY_SQL;
        $inventorySql = self::INVENTORY_SQL;

        $rows = DB::select(<<<SQL
SELECT
    medicines.id AS medicine_id,
    medicines.generic_name,
    medicines.brand_name,
    medicines.strength,
    medicines.dosage_form,
    medicines.manufacturer,
    medicines.prescription_required,
    medicines.normalized_generic_name,
    medicines.normalized_strength,
    medicines.normalized_dosage_form,
    medicines.normalized_manufacturer,
    pharmacy_inventory.id AS inventory_id,
    pharmacy_inventory.version AS inventory_version,
    pharmacy_inventory.unit_price_paisa,
    to_char(pharmacy_inventory.last_verified_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') AS verified_at
FROM pharmacies
INNER JOIN cities ON cities.id = pharmacies.city_id
INNER JOIN pharmacy_inventory ON pharmacy_inventory.pharmacy_id = pharmacies.id
INNER JOIN medicines ON medicines.id = pharmacy_inventory.medicine_id
WHERE pharmacies.id = ?
  AND pharmacy_inventory.medicine_id IN ({$placeholders})
  AND {$pharmacySql}
  AND {$inventorySql}
SQL, [
            $pharmacyId,
            ...$medicineIds,
            $asOf,
            $freshAfter,
        ]);

        $lines = [];

        foreach ($rows as $row) {
            if (! $row instanceof \stdClass) {
                continue;
            }

            if ($this->blank($row->normalized_generic_name)
                || $this->blank($row->normalized_strength)
                || $this->blank($row->normalized_dosage_form)
                || $this->blank($row->normalized_manufacturer)) {
                continue;
            }

            $id = strtolower((string) $row->medicine_id);
            $lines[$id] = [
                'medicine_id' => $id,
                'generic_name' => $this->text($row->generic_name),
                'brand_name' => $this->nullableString($row->brand_name),
                'strength' => $this->nullableString($row->strength),
                'dosage_form' => $this->text($row->dosage_form),
                'manufacturer' => $this->text($row->manufacturer),
                'prescription_required' => $row->prescription_required === true || $row->prescription_required === 't' || $row->prescription_required === 1,
                'inventory_id' => (string) $row->inventory_id,
                'inventory_version' => (int) $row->inventory_version,
                'unit_price_paisa' => (int) $row->unit_price_paisa,
                'verified_at' => (string) $row->verified_at,
            ];
        }

        return $lines;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function blank(mixed $value): bool
    {
        return ! is_string($value) || $value === '';
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
