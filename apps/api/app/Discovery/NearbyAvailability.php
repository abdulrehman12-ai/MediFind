<?php

declare(strict_types=1);

namespace App\Discovery;

use App\Exceptions\ApiProblem;
use App\Models\Medicine;
use App\Models\PharmacyOperatingHour;
use App\Models\User;
use App\Models\UserAddress;
use App\Pharmacy\WeeklyHours;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class NearbyAvailability
{
    public const CONFIRMATION = 'Stock and price require pharmacy confirmation and are not a reservation.';

    /**
     * @param  list<string>  $medicineIds
     * @return array{items: list<array<string, mixed>>, page: int, per_page: int, has_more: bool, radius_meters: int, freshness_hours: int}
     */
    public function paginate(User $customer, array $medicineIds, ?string $addressId, ?string $latitude, ?string $longitude, int $radiusMeters, int $page, int $perPage): array
    {
        $this->assertMedicines($medicineIds);
        [$originLatitude, $originLongitude] = $this->origin($customer, $addressId, $latitude, $longitude);
        $asOf = now()->utc();
        $freshnessHours = (int) config('medifind.discovery.freshness_hours');
        $freshAfter = $asOf->copy()->subHours($freshnessHours);
        $offset = ($page - 1) * $perPage;
        $placeholders = implode(', ', array_fill(0, count($medicineIds), '?'));
        $pharmacySql = PickupEligibility::PHARMACY_SQL;
        $inventorySql = PickupEligibility::INVENTORY_SQL;

        $rows = DB::select(<<<SQL
SELECT
    pharmacies.id,
    pharmacies.name,
    pharmacies.address_text,
    pharmacies.latitude,
    pharmacies.longitude,
    ROUND(ST_Distance(
        pharmacies.location,
        ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography
    ))::int AS distance_meters,
    json_agg(
        json_build_object(
            'id', medicines.id,
            'generic_name', medicines.generic_name,
            'brand_name', medicines.brand_name,
            'strength', medicines.strength,
            'dosage_form', medicines.dosage_form,
            'manufacturer', medicines.manufacturer,
            'prescription_required', medicines.prescription_required,
            'stock_status', pharmacy_inventory.stock_status,
            'unit_price_paisa', pharmacy_inventory.unit_price_paisa,
            'last_verified_at', to_char(
                pharmacy_inventory.last_verified_at AT TIME ZONE 'UTC',
                'YYYY-MM-DD"T"HH24:MI:SS"+00:00"'
            )
        )
        ORDER BY medicines.id
    ) AS medicines
FROM pharmacies
INNER JOIN cities ON cities.id = pharmacies.city_id
INNER JOIN pharmacy_inventory ON pharmacy_inventory.pharmacy_id = pharmacies.id
INNER JOIN medicines ON medicines.id = pharmacy_inventory.medicine_id
WHERE {$pharmacySql}
  AND ST_DWithin(
        pharmacies.location,
        ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
        ?
      )
  AND pharmacy_inventory.medicine_id IN ({$placeholders})
  AND {$inventorySql}
GROUP BY
    pharmacies.id,
    pharmacies.name,
    pharmacies.address_text,
    pharmacies.latitude,
    pharmacies.longitude,
    pharmacies.location
HAVING COUNT(DISTINCT pharmacy_inventory.medicine_id) = ?
ORDER BY ST_Distance(
    pharmacies.location,
    ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography
) ASC, pharmacies.id ASC
LIMIT ? OFFSET ?
SQL, [
            $originLongitude,
            $originLatitude,
            $originLongitude,
            $originLatitude,
            $radiusMeters,
            ...$medicineIds,
            $asOf,
            $freshAfter,
            count($medicineIds),
            $originLongitude,
            $originLatitude,
            $perPage + 1,
            $offset,
        ]);

        $hasMore = count($rows) > $perPage;
        $pageRows = array_slice($rows, 0, $perPage);
        $pharmacyIds = array_map(static fn (object $row): string => (string) $row->id, $pageRows);
        $openNow = $this->openNow($pharmacyIds, $asOf);
        $items = [];

        foreach ($pageRows as $row) {
            $id = (string) $row->id;
            $items[] = [
                'id' => $id,
                'name' => (string) $row->name,
                'address' => (string) $row->address_text,
                'latitude' => $this->coordinate($row->latitude),
                'longitude' => $this->coordinate($row->longitude),
                'distance_meters' => (int) $row->distance_meters,
                'distance_method' => 'straight_line',
                'open_now' => $openNow[$id] ?? null,
                'medicines' => $this->medicineSummaries($row->medicines),
            ];
        }

        return [
            'items' => $items,
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => $hasMore,
            'radius_meters' => $radiusMeters,
            'freshness_hours' => $freshnessHours,
        ];
    }

    /**
     * @param  list<string>  $medicineIds
     */
    private function assertMedicines(array $medicineIds): void
    {
        $canonicalIds = array_map(static fn (string $id): string => strtolower($id), $medicineIds);
        $medicines = Medicine::query()->whereIn('id', $canonicalIds)->get()->keyBy(
            fn (Medicine $medicine): string => strtolower((string) $medicine->id),
        );

        if ($medicines->count() !== count($canonicalIds)) {
            $this->unavailable();
        }

        foreach ($canonicalIds as $id) {
            $medicine = $medicines->get($id);

            if (! $medicine instanceof Medicine || ! PickupEligibility::medicineCanBePickup($medicine)) {
                $this->unavailable();
            }
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function origin(User $customer, ?string $addressId, ?string $latitude, ?string $longitude): array
    {
        if ($addressId !== null) {
            $address = UserAddress::query()
                ->where('public_id', $addressId)
                ->where('user_id', $customer->id)
                ->first();

            if (! $address instanceof UserAddress) {
                throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
            }

            return [(string) $address->latitude, (string) $address->longitude];
        }

        return [(string) $latitude, (string) $longitude];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function medicineSummaries(mixed $payload): array
    {
        $decoded = is_array($payload) ? $payload : json_decode((string) $payload, true);

        if (! is_array($decoded)) {
            return [];
        }

        $summaries = [];

        foreach ($decoded as $row) {
            if (! is_array($row)) {
                continue;
            }

            $summaries[] = [
                'id' => strtolower((string) ($row['id'] ?? '')),
                'generic_name' => (string) ($row['generic_name'] ?? ''),
                'brand_name' => $row['brand_name'] === null ? null : (string) $row['brand_name'],
                'strength' => $row['strength'] === null ? null : (string) $row['strength'],
                'dosage_form' => (string) ($row['dosage_form'] ?? ''),
                'manufacturer' => $row['manufacturer'] === null ? null : (string) $row['manufacturer'],
                'prescription_required' => $row['prescription_required'] === true || $row['prescription_required'] === 't' || $row['prescription_required'] === 1,
                'stock_status' => (string) ($row['stock_status'] ?? ''),
                'unit_price_paisa' => (int) ($row['unit_price_paisa'] ?? 0),
                'last_verified_at' => is_string($row['last_verified_at'] ?? null) ? $row['last_verified_at'] : null,
            ];
        }

        return $summaries;
    }

    /**
     * @param  list<string>  $pharmacyIds
     * @return array<string, bool|null>
     */
    private function openNow(array $pharmacyIds, Carbon $asOf): array
    {
        if ($pharmacyIds === []) {
            return [];
        }

        $local = $asOf->copy()->timezone((string) config('medifind.business_timezone'));
        $seconds = ($local->hour * 3600) + ($local->minute * 60) + $local->second;
        $hours = PharmacyOperatingHour::query()
            ->whereIn('pharmacy_id', $pharmacyIds)
            ->orderBy('id')
            ->get()
            ->groupBy(fn (PharmacyOperatingHour $hour): string => (string) $hour->pharmacy_id);

        $states = [];

        foreach ($pharmacyIds as $pharmacyId) {
            $rows = $hours->get($pharmacyId);

            if ($rows === null || $rows->isEmpty()) {
                $states[$pharmacyId] = null;

                continue;
            }

            $intervals = [];

            foreach ($rows as $row) {
                $intervals[] = [
                    'day_of_week' => (int) $row->day_of_week,
                    'interval_kind' => $row->interval_kind->value,
                    'opens_at' => $row->opens_at,
                    'closes_at' => $row->closes_at,
                ];
            }

            $states[$pharmacyId] = WeeklyHours::covers($intervals, $local->dayOfWeekIso, $seconds);
        }

        return $states;
    }

    private function coordinate(mixed $value): string
    {
        return number_format((float) $value, 6, '.', '');
    }

    private function unavailable(): never
    {
        PickupEligibility::rejectMedicine();
    }
}
