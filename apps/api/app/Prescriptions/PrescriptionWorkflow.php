<?php

declare(strict_types=1);

namespace App\Prescriptions;

use App\Audit\AuditRecorder;
use App\Enums\CredentialStatus;
use App\Enums\MedicineStatus;
use App\Enums\MembershipStatus;
use App\Enums\PharmacyStatus;
use App\Enums\StaffRole;
use App\Exceptions\ApiProblem;
use App\Jobs\ExtractPrescription;
use App\Models\Medicine;
use App\Models\MedicineUsePublication;
use App\Models\Pharmacy;
use App\Models\PharmacyMembership;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\PrescriptionObjectHold;
use App\Models\PrescriptionReview;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class PrescriptionWorkflow
{
    public const NOTICE = 'Extraction and a customer selection are not pharmacist verification. Identification and general-use text do not replace advice from a doctor or pharmacist.';

    public const LIST_PAGE_SIZE = 50;

    public function __construct(
        private readonly PrescriptionFileInspector $inspector,
        private readonly PrescriptionObjectStore $objects,
        private readonly CatalogMatcher $matcher,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Keys displaced by a nested replacement. Storage is erased only after the
     * outermost transaction commits, and only when no live row still names the key.
     *
     * @var list<string>
     */
    private array $deferredErasure = [];

    public function upload(User $customer, UploadedFile $file): Prescription
    {
        $this->hit('upload', (string) $customer->id, (int) config('medifind.prescriptions.upload_limit'), 3600);
        $bytes = $this->inspector->read($file);
        $inspected = $this->inspector->inspect($bytes);
        $key = 'rx/'.Str::uuid()->toString();
        $this->openHold($key);

        try {
            $this->objects->put($key, $bytes);
        } catch (Throwable $exception) {
            $this->abandonUncertainPut($key);

            throw $exception;
        }

        $this->runTestingHook('after_object_put');

        try {
            $prescription = DB::transaction(function () use ($customer, $file, $inspected, $key, $bytes): Prescription {
                $this->attachHold($key);
                $row = new Prescription;
                $row->forceFill([
                    'public_id' => (string) Str::uuid(),
                    'customer_id' => $customer->id,
                    'object_key' => $key,
                    'display_name' => $this->inspector->displayName($file->getClientOriginalName()),
                    'mime_type' => $inspected['mime'],
                    'byte_size' => strlen($bytes),
                    'sha256' => hash('sha256', $bytes),
                    'extraction_state' => 'queued',
                    'extraction_version' => 1,
                    'content_version' => 1,
                    'share_state' => 'none',
                    'share_mode' => null,
                    'shared_content_version' => null,
                    'pharmacy_id' => null,
                    'object_state' => 'stored',
                ]);
                $row->save();
                $this->audit->record($customer, 'prescription_uploaded', 'prescription', (string) $row->id, null, [
                    'mime_type' => $inspected['mime'],
                    'byte_size' => $row->byte_size,
                ]);

                return $row;
            });
        } catch (Throwable $exception) {
            $this->releaseUnattached($key);

            throw $exception;
        }

        $this->queue($prescription);

        return $prescription;
    }

    public function replaceFile(User $customer, Prescription $prescription, UploadedFile $file): Prescription
    {
        $locked = $this->owned($customer, $prescription);
        $bytes = $this->inspector->read($file);
        $inspected = $this->inspector->inspect($bytes);
        $key = 'rx/'.Str::uuid()->toString();
        $this->openHold($key);

        try {
            $this->objects->put($key, $bytes);
        } catch (Throwable $exception) {
            $this->abandonUncertainPut($key);

            throw $exception;
        }

        $this->runTestingHook('after_object_put');
        $superseded = '';

        try {
            $updated = DB::transaction(function () use ($customer, $locked, $file, $inspected, $key, $bytes, &$superseded): Prescription {
                $this->attachHold($key);
                $row = $this->lockOwned($customer, (string) $locked->public_id);
                $this->runTestingHook('before_replace_save');
                $row->refresh();
                $superseded = $row->object_key;

                if ($superseded === '' || $superseded === $key) {
                    throw new ApiProblem('storage_unavailable', 'The prescription could not be stored.', 503);
                }

                $row->forceFill([
                    'object_key' => $key,
                    'display_name' => $this->inspector->displayName($file->getClientOriginalName()),
                    'mime_type' => $inspected['mime'],
                    'byte_size' => strlen($bytes),
                    'sha256' => hash('sha256', $bytes),
                    'extraction_state' => 'queued',
                    'extraction_version' => $row->extraction_version + 1,
                    'content_version' => $row->content_version + 1,
                    'failure_code' => null,
                    'claim_token' => null,
                    'claimed_at' => null,
                ]);
                $this->clearShare($row);
                $row->save();
                $this->scheduleDisplaced($superseded);

                return $row;
            });
        } catch (Throwable $exception) {
            $this->releaseUnattached($key);

            throw $exception;
        }

        $this->eraseIfUnreferenced($superseded);
        $this->queue($updated);

        return $updated;
    }

    public function correct(User $customer, Prescription $prescription, PrescriptionItem $item, ?string $text, ?string $medicineId, bool $selected): Prescription
    {
        $this->hit('edit', (string) $customer->id, (int) config('medifind.prescriptions.edit_limit'), 3600);

        return DB::transaction(function () use ($customer, $prescription, $item, $text, $medicineId, $selected): Prescription {
            $row = $this->lockOwned($customer, (string) $prescription->public_id);
            $lockedItem = PrescriptionItem::query()
                ->whereKey($item->id)
                ->where('prescription_id', $row->id)
                ->where('extraction_version', $row->extraction_version)
                ->lockForUpdate()
                ->first();

            if (! $lockedItem instanceof PrescriptionItem) {
                throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
            }

            if ($text !== null) {
                $visible = trim($text);

                if ($visible === '' || mb_strlen($visible) > 180) {
                    throw ValidationException::withMessages(['text' => ['Enter the line text, up to 180 characters.']]);
                }

                $match = $this->matcher->match($this->parseCorrected($visible));
                $lockedItem->forceFill([
                    'corrected_text' => $visible,
                    'match_status' => $match['status'],
                    'medicine_id' => $match['medicine_id'],
                    'customer_selected' => false,
                    'selected_medicine_id' => null,
                ]);
            }

            if ($selected) {
                if ($lockedItem->match_status !== 'exact' || $lockedItem->medicine_id === null || $medicineId !== $lockedItem->medicine_id) {
                    throw ValidationException::withMessages([
                        'selected_medicine_id' => ['Select the exact catalog medicine shown for this line.'],
                    ]);
                }

                $medicine = $this->lockMedicine($lockedItem->medicine_id, 'selected_medicine_id');
                $this->assertSelectable($medicine, 'selected_medicine_id');
                $lockedItem->customer_selected = true;
                $lockedItem->selected_medicine_id = $lockedItem->medicine_id;
                $lockedItem->selected_fingerprint = $this->fingerprint($medicine);
            } else {
                $lockedItem->customer_selected = false;
                $lockedItem->selected_medicine_id = null;
                $lockedItem->selected_fingerprint = null;
            }

            $lockedItem->save();

            $row->content_version++;
            $this->clearShare($row);
            $row->save();
            $this->audit->record($customer, 'prescription_item_corrected', 'prescription', (string) $row->id, null, [
                'content_version' => $row->content_version,
            ]);

            return $row;
        });
    }

    public function share(User $customer, Prescription $prescription, string $mode, string $pharmacyPublicId): Prescription
    {
        $this->hit('share', (string) $customer->id, (int) config('medifind.prescriptions.share_limit'), 3600);

        return DB::transaction(function () use ($customer, $prescription, $mode, $pharmacyPublicId): Prescription {
            $row = $this->lockOwned($customer, (string) $prescription->public_id);
            $pharmacy = $this->eligiblePharmacy($pharmacyPublicId);

            if ($mode === 'selected_items') {
                $this->assertSelectionStillEligible($row);
            } elseif ($mode !== 'full_image') {
                throw ValidationException::withMessages(['mode' => ['Choose selected items or the complete original prescription.']]);
            }

            $row->forceFill([
                'share_state' => 'pending',
                'share_mode' => $mode,
                'shared_content_version' => $row->content_version,
                'pharmacy_id' => $pharmacy->id,
            ])->save();
            $this->audit->record($customer, 'prescription_shared', 'prescription', (string) $row->id, (string) $pharmacy->id, [
                'share_mode' => $mode,
                'content_version' => $row->content_version,
            ]);

            return $row;
        });
    }

    public function withdraw(User $customer, Prescription $prescription): Prescription
    {
        return DB::transaction(function () use ($customer, $prescription): Prescription {
            $row = $this->lockOwned($customer, (string) $prescription->public_id);

            if (! in_array($row->share_state, ['pending', 'verified', 'rejected'], true)) {
                throw new ApiProblem('not_shared', 'This prescription is not shared.', 409);
            }

            $pharmacyId = $row->pharmacy_id;
            $row->share_state = 'withdrawn';
            $row->save();
            $this->audit->record($customer, 'prescription_withdrawn', 'prescription', (string) $row->id, $pharmacyId, [
                'content_version' => $row->content_version,
            ]);

            return $row->refresh();
        });
    }

    public function delete(User $customer, Prescription $prescription): string
    {
        [$id, $key] = DB::transaction(function () use ($customer, $prescription): array {
            $row = $this->lockOwned($customer, (string) $prescription->public_id);

            if (in_array($row->share_state, ['pending', 'verified', 'rejected'], true)) {
                throw new ApiProblem('share_active', 'Withdraw the pharmacy share before deleting this prescription.', 409);
            }

            $key = $row->object_key;
            PrescriptionItem::query()->where('prescription_id', $row->id)->delete();
            $this->clearShare($row);
            $row->forceFill([
                'deleted_at' => now(),
                'object_state' => 'purge_pending',
                'failure_code' => null,
                'claim_token' => null,
                'display_name' => 'deleted',
            ])->save();
            $this->audit->record($customer, 'prescription_deleted', 'prescription', (string) $row->id, null, [
                'file_erasure' => 'pending',
            ]);

            return [(string) $row->id, $key];
        });

        if (! $this->erase($key)) {
            Log::warning('prescription_object_purge_pending');

            return 'pending';
        }

        Prescription::query()->whereKey($id)->where('object_state', 'purge_pending')->update([
            'object_state' => 'purged',
        ]);
        $this->markHold($key, 'purged');
        $this->audit->record($customer, 'prescription_object_purged', 'prescription', $id, null, [
            'file_erasure' => 'completed',
        ]);

        return 'completed';
    }

    public function review(User $pharmacist, Pharmacy $pharmacy, Prescription $prescription, int $contentVersion, string $decision, ?string $reason): void
    {
        DB::transaction(function () use ($pharmacist, $pharmacy, $prescription, $contentVersion, $decision, $reason): void {
            $membership = $this->lockPharmacist($pharmacist, $pharmacy);
            $row = Prescription::query()->whereKey($prescription->id)->lockForUpdate()->first();

            if (! $row instanceof Prescription
                || $row->deleted_at !== null
                || $row->pharmacy_id !== $pharmacy->id
                || $row->share_state !== 'pending'
                || $row->shared_content_version !== $contentVersion
                || $row->content_version !== $contentVersion) {
                throw new ApiProblem('review_stale', 'This prescription changed before the review was saved.', 409);
            }

            if (! $pharmacy->publiclyEligible() || $pharmacy->status !== PharmacyStatus::Approved) {
                throw new ApiProblem('forbidden', 'This action is unauthorized.', 403);
            }

            if ($decision === 'rejected' && ($reason === null || trim($reason) === '')) {
                throw ValidationException::withMessages(['reason' => ['A rejection needs a reason.']]);
            }

            $review = new PrescriptionReview;
            $review->forceFill([
                'prescription_id' => $row->id,
                'pharmacy_id' => $pharmacy->id,
                'membership_id' => $membership->id,
                'reviewer_id' => $pharmacist->id,
                'content_version' => $row->content_version,
                'extraction_version' => $row->extraction_version,
                'decision' => $decision,
                'reason' => $decision === 'rejected' ? trim((string) $reason) : null,
                'created_at' => now(),
            ]);
            $review->save();
            $row->share_state = $decision === 'verified' ? 'verified' : 'rejected';
            $row->save();
            $this->audit->record($pharmacist, 'prescription_reviewed', 'prescription', (string) $row->id, (string) $pharmacy->id, [
                'decision' => $decision,
                'content_version' => $row->content_version,
            ]);
        });
    }

    public function owned(User $customer, Prescription $prescription): Prescription
    {
        if ($prescription->customer_id !== $customer->id || $prescription->deleted_at !== null) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }

        return $prescription;
    }

    public function findOwned(User $customer, string $publicId): Prescription
    {
        $row = Prescription::query()->where('public_id', $publicId)->first();

        if (! $row instanceof Prescription) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }

        return $this->owned($customer, $row);
    }

    public function pharmacistCanRead(User $pharmacist, Pharmacy $pharmacy, Prescription $prescription): void
    {
        $this->lockPharmacist($pharmacist, $pharmacy);

        if ($prescription->deleted_at !== null
            || $prescription->pharmacy_id !== $pharmacy->id
            || $prescription->share_state !== 'pending'
            || $prescription->shared_content_version !== $prescription->content_version
            || ! $pharmacy->publiclyEligible()) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }
    }

    /**
     * Owned, non-deleted prescriptions, newest first. The id breaks ties so a
     * row created between page requests cannot hide or repeat an older row.
     *
     * @return array{rows: list<Prescription>, has_more: bool, next_cursor: string|null}
     */
    public function listOwned(User $customer, mixed $cursor): array
    {
        $query = $customer->prescriptions()
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $position = $this->listCursor($cursor);

        if ($position !== null) {
            [$createdAt, $id] = $position;
            // A Carbon binding is formatted as Y-m-d H:i:s and drops the fraction.
            $query->where(function ($older) use ($createdAt, $id): void {
                $older->where('created_at', '<', $createdAt)
                    ->orWhere(function ($sameTime) use ($createdAt, $id): void {
                        $sameTime->where('created_at', $createdAt)->where('id', '<', $id);
                    });
            });
        }

        $rows = $query->limit(self::LIST_PAGE_SIZE + 1)->get();
        $hasMore = $rows->count() > self::LIST_PAGE_SIZE;
        $page = $rows->take(self::LIST_PAGE_SIZE)->values();
        $last = $page->last();
        $next = null;

        if ($hasMore && $last instanceof Prescription) {
            $next = $this->encodeListCursor($last);
        }

        return [
            'rows' => $page->all(),
            'has_more' => $hasMore,
            'next_cursor' => $next,
        ];
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function listCursor(mixed $cursor): ?array
    {
        if ($cursor === null) {
            return null;
        }

        if (! is_string($cursor) || $cursor === '') {
            throw ValidationException::withMessages(['cursor' => ['The cursor is invalid.']]);
        }

        $padded = $cursor.str_repeat('=', (4 - strlen($cursor) % 4) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        $payload = is_string($decoded) ? json_decode($decoded, true) : null;
        $createdAt = is_array($payload) ? ($payload['created_at'] ?? null) : null;
        $id = is_array($payload) ? ($payload['id'] ?? null) : null;
        $parsed = false;

        if (is_string($createdAt)) {
            try {
                $parsed = Carbon::createFromFormat('!Y-m-d H:i:s.u', $createdAt, 'UTC');
            } catch (Throwable) {
                $parsed = false;
            }
        }

        if (! $parsed instanceof Carbon
            || ! is_string($createdAt)
            || $parsed->format('Y-m-d H:i:s.u') !== $createdAt
            || ! is_string($id)
            || ! Str::isUuid($id)) {
            throw ValidationException::withMessages(['cursor' => ['The cursor is invalid.']]);
        }

        return [$createdAt, $id];
    }

    private function encodeListCursor(Prescription $prescription): string
    {
        $createdAt = $prescription->created_at instanceof Carbon
            ? $prescription->created_at->copy()->utc()->format('Y-m-d H:i:s.u')
            : Carbon::parse($prescription->created_at)->utc()->format('Y-m-d H:i:s.u');

        return rtrim(strtr(base64_encode(json_encode([
            'created_at' => $createdAt,
            'id' => $prescription->id,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Prescription $prescription): array
    {
        $items = $prescription->items()
            ->where('extraction_version', $prescription->extraction_version)
            ->orderBy('line_index')
            ->get();
        $selected = $items->where('customer_selected', true)->count();
        $limit = (int) config('medifind.discovery.max_medicines');
        $current = $prescription->share_state !== 'none'
            && $prescription->shared_content_version === $prescription->content_version;

        return [
            'id' => $prescription->public_id,
            'extraction_state' => $prescription->extraction_state,
            'failure_code' => $prescription->failure_code,
            'status_text' => $this->statusText($prescription),
            'share_state' => $current || $prescription->share_state === 'withdrawn' ? $prescription->share_state : 'none',
            'share_mode' => $current ? $prescription->share_mode : null,
            'content_version' => $prescription->content_version,
            'pharmacy_verified' => $current && $prescription->share_state === 'verified',
            'verification_scope' => 'A pharmacist decision applies only to this prescription version. Sharing does not reserve stock or confirm an order. Any future order confirmation must check medicine eligibility again.',
            'safety_notice' => self::NOTICE,
            'full_image_contents_identified' => false,
            'availability' => [
                'limit' => $limit,
                'selected_count' => $selected,
                'blocked' => $selected > $limit,
                'message' => $selected > $limit
                    ? 'Nearby availability accepts at most '.$limit.' selected medicines. Narrow the selection. The list was not shortened.'
                    : null,
            ],
            'items' => $items->map(function (PrescriptionItem $item): array {
                return [
                    'id' => $item->public_id,
                    'line' => $item->line_index,
                    'extracted_text' => $item->extracted_text,
                    'corrected_text' => $item->corrected_text,
                    'confidence' => null,
                    'uncertainty' => 'No confidence score is available for this line.',
                    'match_status' => $item->match_status,
                    'medicine_id' => $item->medicine_id,
                    'customer_selected' => $item->customer_selected,
                    'selected_medicine_id' => $item->selected_medicine_id,
                ];
            })->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function generalUse(string $medicineId): array
    {
        $publication = MedicineUsePublication::query()->where('medicine_id', $medicineId)->where('locale', 'en-PK')->first();

        if (! $publication instanceof MedicineUsePublication) {
            return [
                'medicine_id' => $medicineId,
                'available' => false,
                'general_use' => null,
                'safety_disclaimer' => self::NOTICE,
                'message' => 'No reviewed general-use information is available.',
            ];
        }

        return [
            'medicine_id' => $medicineId,
            'available' => true,
            'general_use' => $publication->general_use,
            'safety_disclaimer' => $publication->safety_disclaimer.' '.self::NOTICE,
            'message' => null,
        ];
    }

    private function queue(Prescription $prescription): void
    {
        $dispatch = function () use ($prescription): void {
            if (DB::transactionLevel() > 0) {
                return;
            }

            ExtractPrescription::dispatch($prescription->id, $prescription->extraction_version);
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);

            return;
        }

        $dispatch();
    }

    private function lockOwned(User $customer, string $publicId): Prescription
    {
        $row = Prescription::query()->where('public_id', $publicId)->lockForUpdate()->first();

        if (! $row instanceof Prescription || $row->customer_id !== $customer->id || $row->deleted_at !== null) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }

        return $row;
    }

    private function clearShare(Prescription $row): void
    {
        $row->share_state = 'none';
        $row->share_mode = null;
        $row->shared_content_version = null;
        $row->pharmacy_id = null;
    }

    private function eligiblePharmacy(string $publicId): Pharmacy
    {
        $pharmacy = Pharmacy::query()->whereKey($publicId)->with('city')->first();

        if (! $pharmacy instanceof Pharmacy || ! $pharmacy->publiclyEligible()) {
            throw new ApiProblem('pharmacy_unavailable', 'That pharmacy cannot receive a prescription.', 422);
        }

        $city = $pharmacy->city;

        if ($city === null || $city->name !== 'Faisalabad' || $city->country_code !== 'PK' || ! $city->is_active) {
            throw new ApiProblem('pharmacy_unavailable', 'That pharmacy cannot receive a prescription.', 422);
        }

        return $pharmacy;
    }

    private function lockPharmacist(User $user, Pharmacy $pharmacy): PharmacyMembership
    {
        $pharmacy->refresh();

        if (! $user->isOperational() || $pharmacy->status !== PharmacyStatus::Approved || ! $pharmacy->publiclyEligible()) {
            throw new ApiProblem('forbidden', 'This action is unauthorized.', 403);
        }

        $membership = PharmacyMembership::query()
            ->where('pharmacy_id', $pharmacy->id)
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();

        if (! $membership instanceof PharmacyMembership
            || $membership->status !== MembershipStatus::Active
            || $membership->staff_role !== StaffRole::Pharmacist
            || $membership->credential_status !== CredentialStatus::Verified) {
            throw new ApiProblem('forbidden', 'This action is unauthorized.', 403);
        }

        return $membership;
    }

    /**
     * @return array{text: string, generic_name: string|null, brand_name: string|null, strength: string|null, dosage_form: string|null, manufacturer: string|null}
     */
    private function parseCorrected(string $text): array
    {
        $parts = array_map('trim', explode(',', $text));

        return [
            'text' => $text,
            'generic_name' => $parts[0],
            'strength' => $parts[1] ?? null,
            'dosage_form' => $parts[2] ?? null,
            'manufacturer' => $parts[3] ?? null,
            'brand_name' => $parts[4] ?? null,
        ];
    }

    private function assertSelectionStillEligible(Prescription $row): void
    {
        $items = $row->items()
            ->where('extraction_version', $row->extraction_version)
            ->where('customer_selected', true)
            ->lockForUpdate()
            ->get();

        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['mode' => ['Select at least one exact catalog medicine before sharing items.']]);
        }

        foreach ($items as $item) {
            if ($item->selected_medicine_id === null || $item->selected_medicine_id !== $item->medicine_id || $item->match_status !== 'exact') {
                throw ValidationException::withMessages([
                    'mode' => ['A selected medicine is no longer the same active, non-controlled catalog variant. Review the selection before sharing.'],
                ]);
            }

            $medicine = $this->lockMedicine($item->selected_medicine_id);
            $this->assertSelectable($medicine);

            if ($item->selected_fingerprint !== $this->fingerprint($medicine)) {
                throw ValidationException::withMessages([
                    'mode' => ['A selected medicine is no longer the same active, non-controlled catalog variant. Review the selection before sharing.'],
                ]);
            }
        }
    }

    private function lockMedicine(string $medicineId, string $field = 'mode'): Medicine
    {
        $medicine = Medicine::query()->whereKey($medicineId)->lockForUpdate()->first();

        if (! $medicine instanceof Medicine) {
            throw ValidationException::withMessages([
                $field => ['A selected medicine is no longer the same active, non-controlled catalog variant. Review the selection before sharing.'],
            ]);
        }

        return $medicine;
    }

    private function assertSelectable(Medicine $medicine, string $field = 'mode'): void
    {
        if ($medicine->status !== MedicineStatus::Active || $medicine->controlled_drug) {
            throw ValidationException::withMessages([
                $field => ['A selected medicine is no longer the same active, non-controlled catalog variant. Review the selection before sharing.'],
            ]);
        }
    }

    private function fingerprint(Medicine $medicine): string
    {
        return hash('sha256', implode("\n", [
            (string) $medicine->id,
            $medicine->normalized_generic_name,
            $medicine->normalized_strength,
            $medicine->normalized_dosage_form,
            $medicine->normalized_manufacturer,
            $medicine->controlled_drug ? '1' : '0',
            $medicine->status->value,
        ]));
    }

    private function statusText(Prescription $prescription): string
    {
        return match ($prescription->failure_code) {
            'not_extracted' => 'This file was stored for pharmacist review and was not read as a prescription.',
            'pdf_not_extracted' => 'PDF text was not extracted. The original file can be shared for pharmacist review.',
            'provider_not_approved' => 'No approved prescription reader is configured. The original file can be shared for pharmacist review.',
            default => match ($prescription->extraction_state) {
                'queued' => 'The prescription is waiting to be read.',
                'processing' => 'The prescription is being read.',
                'completed' => 'Reading finished. These lines are candidates, not a pharmacist verification.',
                'failed' => 'The prescription could not be read. The original file can still be shared for pharmacist review.',
                default => 'The prescription status is unavailable.',
            },
        };
    }

    private function openHold(string $key): void
    {
        $hold = new PrescriptionObjectHold;
        $hold->forceFill([
            'object_key' => $key,
            'status' => 'open',
            'created_at' => now(),
        ])->save();
    }

    private function attachHold(string $key): void
    {
        $attached = PrescriptionObjectHold::query()
            ->where('object_key', $key)
            ->where('status', 'open')
            ->update(['status' => 'attached']);

        if ($attached !== 1) {
            throw new ApiProblem('storage_unavailable', 'The prescription could not be stored.', 503);
        }
    }

    private function markHold(string $key, string $status): void
    {
        PrescriptionObjectHold::query()->where('object_key', $key)->update(['status' => $status]);
    }

    private function releaseUnattached(string $key): void
    {
        $this->markHold($key, $this->erase($key) ? 'purged' : 'purge_pending');
    }

    private function scheduleDisplaced(string $key): void
    {
        $updated = PrescriptionObjectHold::query()
            ->where('object_key', $key)
            ->where('status', '!=', 'purged')
            ->update(['status' => 'purge_pending']);

        if ($updated === 0 && ! PrescriptionObjectHold::query()->where('object_key', $key)->exists()) {
            $hold = new PrescriptionObjectHold;
            $hold->forceFill([
                'object_key' => $key,
                'status' => 'purge_pending',
                'created_at' => now(),
            ])->save();
        }
    }

    /**
     * The row lock is not held while storage is called. A key still named by a
     * live prescription row is not deleted. An uncertain PUT is never marked
     * purged here; only a completed delete may do that.
     */
    private function eraseIfUnreferenced(string $key): void
    {
        if ($key === '') {
            return;
        }

        if (DB::transactionLevel() > 0) {
            if (count($this->deferredErasure) < 8) {
                $this->deferredErasure[] = $key;
            }

            return;
        }

        $keys = array_values(array_unique([$key, ...$this->deferredErasure]));
        $this->deferredErasure = [];

        foreach ($keys as $candidate) {
            $this->eraseUnreferencedNow($candidate);
        }
    }

    private function eraseUnreferencedNow(string $key): void
    {
        if ($key === '') {
            return;
        }

        if (Prescription::query()->where('object_key', $key)->whereNull('deleted_at')->exists()) {
            $this->markHold($key, 'attached');

            return;
        }

        if ($this->erase($key)) {
            $this->markHold($key, 'purged');

            return;
        }

        $this->markHold($key, 'purge_pending');
        Log::warning('prescription_object_purge_pending');
    }

    private function abandonUncertainPut(string $key): void
    {
        $this->markHold($key, 'purge_pending');
        Log::warning('prescription_object_purge_pending');
    }

    private function erase(string $key): bool
    {
        try {
            return $this->objects->delete($key);
        } catch (Throwable) {
            Log::warning('prescription_object_delete_failed');

            return false;
        }
    }

    private function runTestingHook(string $name): void
    {
        if (! app()->environment('testing')) {
            return;
        }

        $hook = config('medifind.prescriptions.testing_hooks.'.$name);

        if (is_callable($hook)) {
            $hook();
        }
    }

    private function hit(string $action, string $actor, int $limit, int $seconds): void
    {
        $key = 'prescription:'.$action.':'.$actor;

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            throw new ApiProblem('prescription_rate_limited', 'Too many prescription requests.', 429, [], RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, $seconds);
    }
}
