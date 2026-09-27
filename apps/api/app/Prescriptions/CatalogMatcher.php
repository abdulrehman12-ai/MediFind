<?php

declare(strict_types=1);

namespace App\Prescriptions;

use App\Catalog\CatalogText;
use App\Enums\MedicineStatus;
use App\Models\Medicine;

final class CatalogMatcher
{
    /**
     * @param  array{text?: string|null, generic_name?: string|null, brand_name?: string|null, strength?: string|null, dosage_form?: string|null, manufacturer?: string|null}  $line
     * @return array{status: string, medicine_id: string|null}
     */
    public function match(array $line): array
    {
        $generic = CatalogText::normalize($line['generic_name'] ?? null);
        $brand = CatalogText::normalize($line['brand_name'] ?? null);
        $strength = CatalogText::normalize($line['strength'] ?? null);
        $form = CatalogText::normalize($line['dosage_form'] ?? null);
        $manufacturer = CatalogText::normalize($line['manufacturer'] ?? null);

        if ($strength === '' || $form === '' || ($generic === '' && $brand === '')) {
            return ['status' => 'unmatched', 'medicine_id' => null];
        }

        $query = Medicine::query()
            ->where('normalized_strength', $strength)
            ->where('normalized_dosage_form', $form);

        if ($manufacturer !== '') {
            $query->where('normalized_manufacturer', $manufacturer);
        }

        $query->where(function ($inner) use ($generic, $brand): void {
            if ($generic !== '') {
                $inner->orWhere('normalized_generic_name', $generic);
            }

            if ($brand !== '') {
                $inner->orWhere('normalized_brand_name', $brand);
            }
        });

        $rows = $query->limit(5)->get();

        if ($rows->count() !== 1) {
            return ['status' => $rows->isEmpty() ? 'unmatched' : 'ambiguous', 'medicine_id' => null];
        }

        /** @var Medicine $medicine */
        $medicine = $rows->first();

        if ($generic !== '' && $medicine->normalized_generic_name !== $generic) {
            return ['status' => 'ambiguous', 'medicine_id' => null];
        }

        if ($brand !== '' && $medicine->normalized_brand_name !== $brand && $medicine->normalized_brand_name !== '') {
            return ['status' => 'ambiguous', 'medicine_id' => null];
        }

        if ($medicine->status !== MedicineStatus::Active || $medicine->controlled_drug) {
            return ['status' => 'ineligible', 'medicine_id' => null];
        }

        return ['status' => 'exact', 'medicine_id' => (string) $medicine->id];
    }
}
