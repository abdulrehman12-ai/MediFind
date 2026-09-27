<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Audit\AuditRecorder;
use App\Enums\MedicineStatus;
use App\Models\Medicine;
use App\Models\MedicineAlias;
use App\Models\MedicineUse;
use App\Models\MedicineUsePublication;
use App\Models\User;
use App\Pharmacy\PharmacyAccess;
use App\Pharmacy\PharmacyRuleException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class MedicineCatalog
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array{generic_name: string, dosage_form: string, brand_name?: ?string, strength?: ?string, manufacturer?: ?string, prescription_required?: bool, controlled_drug?: bool}  $attributes
     */
    public function create(User $admin, array $attributes): Medicine
    {
        PharmacyAccess::admin($admin);

        return DB::transaction(function () use ($admin, $attributes): Medicine {
            $medicine = new Medicine;
            $medicine->forceFill($this->identity($attributes) + [
                'prescription_required' => (bool) ($attributes['prescription_required'] ?? false),
                'controlled_drug' => (bool) ($attributes['controlled_drug'] ?? false),
                'status' => MedicineStatus::Inactive,
            ]);
            $medicine->save();

            $this->audit->record($admin, 'medicine_drafted', 'medicine', (string) $medicine->id, null);

            return $medicine;
        });
    }

    /**
     * @param  array{generic_name: string, dosage_form: string, brand_name?: ?string, strength?: ?string, manufacturer?: ?string, prescription_required?: bool, controlled_drug?: bool}  $attributes
     */
    public function update(User $admin, Medicine $medicine, array $attributes): Medicine
    {
        PharmacyAccess::admin($admin);

        $changes = $this->identity($attributes) + [
            'prescription_required' => (bool) ($attributes['prescription_required'] ?? $medicine->prescription_required),
            'controlled_drug' => (bool) ($attributes['controlled_drug'] ?? $medicine->controlled_drug),
        ];

        return DB::transaction(function () use ($admin, $medicine, $changes): Medicine {
            $locked = Medicine::query()->whereKey($medicine->id)->lockForUpdate()->first();

            if (! $locked instanceof Medicine) {
                throw new PharmacyRuleException('That medicine is no longer available.');
            }

            $locked->forceFill($changes);

            if ($locked->status === MedicineStatus::Active && ! $locked->identityIsComplete()) {
                throw new PharmacyRuleException('An active medicine needs a generic name, strength, dosage form, and manufacturer.');
            }

            $locked->save();
            $this->audit->record($admin, 'medicine_updated', 'medicine', (string) $locked->id, null);

            return $locked->refresh();
        });
    }

    public function activate(User $admin, Medicine $medicine): void
    {
        PharmacyAccess::admin($admin);

        DB::transaction(function () use ($admin, $medicine): void {
            $locked = Medicine::query()->whereKey($medicine->id)->lockForUpdate()->first();

            if (! $locked instanceof Medicine || ! $locked->identityIsComplete()) {
                throw new PharmacyRuleException('An active medicine needs a generic name, strength, dosage form, and manufacturer.');
            }

            $locked->forceFill(['status' => MedicineStatus::Active])->save();
            $this->audit->record($admin, 'medicine_activated', 'medicine', (string) $locked->id, null);
        });
    }

    public function deactivate(User $admin, Medicine $medicine): void
    {
        PharmacyAccess::admin($admin);

        DB::transaction(function () use ($admin, $medicine): void {
            $locked = Medicine::query()->whereKey($medicine->id)->lockForUpdate()->first();

            if (! $locked instanceof Medicine) {
                throw new PharmacyRuleException('That medicine is no longer available.');
            }

            $locked->forceFill(['status' => MedicineStatus::Inactive])->save();
            $this->audit->record($admin, 'medicine_deactivated', 'medicine', (string) $locked->id, null);
        });
    }

    public function addAlias(User $admin, Medicine $medicine, string $alias, string $type): MedicineAlias
    {
        PharmacyAccess::admin($admin);
        $normalized = CatalogText::normalize($alias);

        if ($normalized === '') {
            throw new PharmacyRuleException('An alias needs text.');
        }

        try {
            return DB::transaction(function () use ($admin, $medicine, $alias, $type, $normalized): MedicineAlias {
                $row = new MedicineAlias;
                $row->forceFill([
                    'medicine_id' => $medicine->id,
                    'alias' => trim($alias),
                    'alias_type' => $type,
                    'normalized_alias' => $normalized,
                ]);
                $row->save();
                $this->audit->record($admin, 'medicine_alias_added', 'medicine', (string) $medicine->id, null);

                return $row;
            });
        } catch (UniqueConstraintViolationException) {
            throw new PharmacyRuleException('That alias is already recorded for this medicine.');
        }
    }

    public function draftUse(User $admin, Medicine $medicine, string $generalUse, string $disclaimer, string $source): MedicineUse
    {
        PharmacyAccess::admin($admin);

        return DB::transaction(function () use ($admin, $medicine, $generalUse, $disclaimer, $source): MedicineUse {
            $use = MedicineUse::query()
                ->where('medicine_id', $medicine->id)
                ->where('locale', 'en-PK')
                ->lockForUpdate()
                ->first() ?? new MedicineUse;
            $use->forceFill([
                'medicine_id' => $medicine->id,
                'locale' => 'en-PK',
                'general_use' => trim($generalUse),
                'safety_disclaimer' => trim($disclaimer),
                'source_citation' => trim($source),
                'review_status' => 'draft',
                'drafted_by' => $admin->id,
            ]);
            $use->save();
            $this->audit->record($admin, 'medicine_use_drafted', 'medicine_use', (string) $use->id, null);

            return $use;
        });
    }

    public function publishUse(User $admin, Medicine $medicine): MedicineUsePublication
    {
        PharmacyAccess::admin($admin);

        return DB::transaction(function () use ($admin, $medicine): MedicineUsePublication {
            $draft = MedicineUse::query()
                ->where('medicine_id', $medicine->id)
                ->where('locale', 'en-PK')
                ->lockForUpdate()
                ->first();

            if (! $draft instanceof MedicineUse || trim($draft->general_use) === '') {
                throw new PharmacyRuleException('Publish a general-use draft before customers can see it.');
            }

            $publication = MedicineUsePublication::query()
                ->where('medicine_id', $medicine->id)
                ->where('locale', 'en-PK')
                ->lockForUpdate()
                ->first() ?? new MedicineUsePublication;
            $publication->forceFill([
                'medicine_id' => $medicine->id,
                'medicine_use_id' => $draft->id,
                'locale' => 'en-PK',
                'general_use' => $draft->general_use,
                'safety_disclaimer' => $draft->safety_disclaimer,
                'source_citation' => $draft->source_citation,
                'published_by' => $admin->id,
                'published_at' => now(),
            ]);
            $publication->save();
            $this->audit->record($admin, 'medicine_use_published', 'medicine_use', (string) $draft->id, null, [
                'publication_id' => (string) $publication->id,
            ]);

            return $publication;
        });
    }

    /**
     * @param  array{generic_name: string, dosage_form: string, brand_name?: ?string, strength?: ?string, manufacturer?: ?string}  $attributes
     * @return array<string, string|null>
     */
    private function identity(array $attributes): array
    {
        $generic = CatalogText::normalize($attributes['generic_name']);
        $form = CatalogText::normalize($attributes['dosage_form']);

        if ($generic === '' || $form === '') {
            throw new PharmacyRuleException('A medicine needs a generic name and dosage form.');
        }

        return [
            'generic_name' => trim($attributes['generic_name']),
            'brand_name' => $this->nullable($attributes['brand_name'] ?? null),
            'strength' => $this->nullable($attributes['strength'] ?? null),
            'dosage_form' => trim($attributes['dosage_form']),
            'manufacturer' => $this->nullable($attributes['manufacturer'] ?? null),
            'normalized_generic_name' => $generic,
            'normalized_brand_name' => CatalogText::normalize($attributes['brand_name'] ?? null),
            'normalized_strength' => CatalogText::normalize($attributes['strength'] ?? null),
            'normalized_dosage_form' => $form,
            'normalized_manufacturer' => CatalogText::normalize($attributes['manufacturer'] ?? null),
        ];
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
