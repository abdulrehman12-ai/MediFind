<?php

declare(strict_types=1);

namespace App\Prescriptions;

use App\Enums\MedicineStatus;
use App\Models\Medicine;
use App\Models\PrescriptionItem;

/**
 * Exact variant identity for a customer-selected prescription line.
 *
 * The fingerprint is the SHA-256 hex of these fields joined by newlines:
 * medicine UUID, normalized generic name, normalized brand name, normalized
 * strength, normalized dosage form, normalized manufacturer, controlled flag
 * (1 or 0), and medicine status. It is 64 characters and fits selected_fingerprint.
 *
 * A fingerprint written before brand was included cannot prove the selected
 * brand. It does not match, and nothing here rewrites it. The customer selects
 * the line again through correction, which stores a new fingerprint and a new
 * content version.
 */
final class SelectionIdentity
{
    public function fingerprint(Medicine $medicine): string
    {
        $status = $medicine->status->value;

        return hash('sha256', implode("\n", [
            strtolower((string) $medicine->id),
            $medicine->normalized_generic_name,
            $medicine->normalized_brand_name,
            $medicine->normalized_strength,
            $medicine->normalized_dosage_form,
            $medicine->normalized_manufacturer,
            $medicine->controlled_drug ? '1' : '0',
            $status,
        ]));
    }

    public function matches(PrescriptionItem $item, Medicine $medicine): bool
    {
        if ($item->match_status !== 'exact' || ! is_string($item->medicine_id) || ! is_string($item->selected_medicine_id)) {
            return false;
        }

        $selectedId = strtolower($item->selected_medicine_id);
        $stored = trim((string) $item->selected_fingerprint);

        return $selectedId === strtolower($item->medicine_id)
            && $selectedId === strtolower((string) $medicine->id)
            && strlen($stored) === 64
            && $medicine->status === MedicineStatus::Active
            && ! $medicine->controlled_drug
            && $medicine->identityIsComplete()
            && hash_equals($this->fingerprint($medicine), $stored);
    }
}
