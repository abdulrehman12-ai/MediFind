<?php

declare(strict_types=1);

namespace App\Pharmacy;

use App\Audit\AuditRecorder;
use App\Enums\CredentialStatus;
use App\Enums\LicenseStatus;
use App\Enums\LocationReviewStatus;
use App\Enums\MembershipStatus;
use App\Enums\PharmacyStatus;
use App\Enums\StaffRole;
use App\Enums\UserRole;
use App\Models\City;
use App\Models\Pharmacy;
use App\Models\PharmacyMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class PharmacyWorkflow
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array{name: string, address_text: string, phone_e164?: ?string, latitude?: ?string, longitude?: ?string, pickup_instructions?: ?string, license_number?: ?string}  $attributes
     */
    public function create(User $admin, array $attributes): Pharmacy
    {
        PharmacyAccess::admin($admin);
        $city = $this->faisalabad();
        Coordinates::assertPair($attributes['latitude'] ?? null, $attributes['longitude'] ?? null);

        return DB::transaction(function () use ($admin, $attributes, $city): Pharmacy {
            $pharmacy = new Pharmacy;
            $pharmacy->forceFill([
                'city_id' => $city->id,
                'name' => $attributes['name'],
                'address_text' => $attributes['address_text'],
                'phone_e164' => $attributes['phone_e164'] ?? null,
                'latitude' => $attributes['latitude'] ?? null,
                'longitude' => $attributes['longitude'] ?? null,
                'pickup_instructions' => $attributes['pickup_instructions'] ?? null,
                'license_number' => $this->blankToNull($attributes['license_number'] ?? null),
                'status' => PharmacyStatus::Pending,
                'license_status' => LicenseStatus::Unverified,
                'location_review_status' => LocationReviewStatus::Pending,
            ]);
            $pharmacy->save();

            $this->audit->record($admin, 'pharmacy_created', 'pharmacy', (string) $pharmacy->id, (string) $pharmacy->id, [
                'status' => PharmacyStatus::Pending->value,
            ]);

            return $pharmacy;
        });
    }

    /**
     * @param  array{name?: string, address_text?: string, phone_e164?: ?string, latitude?: ?string, longitude?: ?string, pickup_instructions?: ?string, license_number?: ?string}  $attributes
     */
    public function updateProfile(User $actor, Pharmacy $pharmacy, array $attributes): Pharmacy
    {
        if ($actor->role->value === 'admin') {
            PharmacyAccess::admin($actor);
        } else {
            PharmacyAccess::manager($actor, $pharmacy);
        }

        return DB::transaction(function () use ($actor, $pharmacy, $attributes): Pharmacy {
            $locked = $this->lockPharmacy($pharmacy);

            if ($actor->role !== UserRole::Admin && ! in_array($locked->status, [PharmacyStatus::Pending, PharmacyStatus::Approved, PharmacyStatus::Rejected], true)) {
                throw new PharmacyRuleException('This pharmacy cannot be edited.');
            }

            $latitude = array_key_exists('latitude', $attributes) ? $attributes['latitude'] : $locked->latitude;
            $longitude = array_key_exists('longitude', $attributes) ? $attributes['longitude'] : $locked->longitude;
            Coordinates::assertPair($latitude, $longitude);

            $originalLicense = $locked->license_number;
            $originalLatitude = $locked->latitude;
            $originalLongitude = $locked->longitude;
            $incoming = array_intersect_key($attributes, array_flip([
                'name', 'address_text', 'phone_e164', 'latitude', 'longitude', 'pickup_instructions', 'license_number',
            ]));

            if (array_key_exists('license_number', $incoming)) {
                $incoming['license_number'] = $this->blankToNull($incoming['license_number']);
            }

            $locked->forceFill($incoming);

            if ($this->blankToNull($locked->license_number) !== $this->blankToNull($originalLicense)) {
                $this->clearLicenseVerification($locked);
            }

            if ($this->coordinatesDiffer($locked->latitude, $originalLatitude) || $this->coordinatesDiffer($locked->longitude, $originalLongitude)) {
                $this->clearLocationVerification($locked);
            }

            $locked->save();
            $this->audit->record($actor, 'pharmacy_profile_updated', 'pharmacy', (string) $locked->id, (string) $locked->id);

            return $locked->refresh();
        });
    }

    public function approve(User $admin, Pharmacy $pharmacy): void
    {
        $this->changeStatus($admin, $pharmacy, PharmacyStatus::Approved, null, [PharmacyStatus::Pending], false);
    }

    public function reject(User $admin, Pharmacy $pharmacy, string $reason): void
    {
        $this->changeStatus($admin, $pharmacy, PharmacyStatus::Rejected, $reason, [PharmacyStatus::Pending], false);
    }

    public function suspend(User $admin, Pharmacy $pharmacy, string $reason): void
    {
        $this->changeStatus($admin, $pharmacy, PharmacyStatus::Suspended, $reason, [PharmacyStatus::Approved], true);
    }

    public function reinstate(User $admin, Pharmacy $pharmacy): void
    {
        $this->changeStatus($admin, $pharmacy, PharmacyStatus::Approved, null, [PharmacyStatus::Suspended], true);
    }

    public function appeal(User $manager, Pharmacy $pharmacy, string $note): void
    {
        PharmacyAccess::manager($manager, $pharmacy);
        $note = trim($note);

        if ($note === '') {
            throw new PharmacyRuleException('An appeal needs a note for the administrator.');
        }

        DB::transaction(function () use ($manager, $pharmacy, $note): void {
            $locked = $this->lockPharmacy($pharmacy);

            if ($locked->status !== PharmacyStatus::Rejected) {
                throw new PharmacyRuleException('Only a rejected pharmacy can be submitted for another review.');
            }

            $locked->forceFill([
                'status' => PharmacyStatus::Pending,
                'appeal_note' => $note,
                'appeal_submitted_at' => now(),
            ])->save();

            $this->audit->record($manager, 'pharmacy_appealed', 'pharmacy', (string) $locked->id, (string) $locked->id);
        });
    }

    public function verifyLicense(User $admin, Pharmacy $pharmacy, string $evidence): void
    {
        PharmacyAccess::admin($admin);
        $evidence = trim($evidence);
        $expectedLicense = $this->blankToNull($pharmacy->license_number);

        if ($evidence === '' || $expectedLicense === null) {
            throw new PharmacyRuleException('License verification requires a license number and evidence notes.');
        }

        DB::transaction(function () use ($admin, $pharmacy, $evidence, $expectedLicense): void {
            $locked = $this->lockPharmacy($pharmacy);
            $currentLicense = $this->blankToNull($locked->license_number);

            if ($currentLicense === null || $currentLicense !== $expectedLicense) {
                throw new PharmacyRuleException('The license number changed before it could be verified. Review the current number.');
            }

            $locked->forceFill([
                'license_status' => LicenseStatus::Verified,
                'license_reviewed_by' => $admin->id,
                'license_reviewed_at' => now(),
                'license_verification_method' => 'documented_admin_review',
                'license_evidence' => $evidence,
            ])->save();

            $this->audit->record($admin, 'pharmacy_license_verified', 'pharmacy', (string) $locked->id, (string) $locked->id, [
                'method' => 'documented_admin_review',
            ]);
        });
    }

    public function approveLocation(User $admin, Pharmacy $pharmacy, string $evidence): void
    {
        PharmacyAccess::admin($admin);
        $evidence = trim($evidence);

        if ($evidence === '' || $pharmacy->latitude === null || $pharmacy->longitude === null) {
            throw new PharmacyRuleException('Location approval requires coordinates and evidence notes.');
        }

        $expectedLatitude = (string) $pharmacy->latitude;
        $expectedLongitude = (string) $pharmacy->longitude;

        DB::transaction(function () use ($admin, $pharmacy, $evidence, $expectedLatitude, $expectedLongitude): void {
            $locked = $this->lockPharmacy($pharmacy);

            if ($locked->latitude === null || $locked->longitude === null
                || $this->coordinatesDiffer($locked->latitude, $expectedLatitude)
                || $this->coordinatesDiffer($locked->longitude, $expectedLongitude)) {
                throw new PharmacyRuleException('The location changed before it could be approved. Review the current coordinates.');
            }

            $locked->forceFill([
                'location_review_status' => LocationReviewStatus::Approved,
                'location_reviewed_by' => $admin->id,
                'location_reviewed_at' => now(),
                'location_verification_method' => 'documented_admin_review',
                'location_evidence' => $evidence,
            ])->save();

            $this->audit->record($admin, 'pharmacy_location_approved', 'pharmacy', (string) $locked->id, (string) $locked->id, [
                'method' => 'documented_admin_review',
            ]);
        });
    }

    public function assignPharmacist(User $admin, Pharmacy $pharmacy, User $staff, ?string $licenseNumber): PharmacyMembership
    {
        PharmacyAccess::admin($admin);

        if ($staff->role !== UserRole::PharmacyStaff || ! $staff->isOperational()) {
            throw new PharmacyRuleException('Only an active pharmacy staff account can be assigned as a pharmacist.');
        }

        $licenseNumber = $this->blankToNull($licenseNumber);

        return DB::transaction(function () use ($admin, $pharmacy, $staff, $licenseNumber): PharmacyMembership {
            $this->lockPharmacy($pharmacy);
            $membership = PharmacyMembership::query()
                ->where('pharmacy_id', $pharmacy->id)
                ->where('user_id', $staff->id)
                ->lockForUpdate()
                ->first();

            if (! $membership instanceof PharmacyMembership) {
                $membership = new PharmacyMembership;
                $membership->forceFill([
                    'pharmacy_id' => $pharmacy->id,
                    'user_id' => $staff->id,
                    'staff_role' => StaffRole::Pharmacist,
                    'status' => MembershipStatus::Active,
                    'license_number' => $licenseNumber,
                    'credential_status' => CredentialStatus::Unverified,
                ]);
                $membership->save();
            } else {
                $membership->forceFill([
                    'staff_role' => StaffRole::Pharmacist,
                    'status' => MembershipStatus::Active,
                    'license_number' => $licenseNumber,
                ])->save();
            }

            $this->audit->record($admin, 'pharmacist_assigned', 'pharmacy_membership', (string) $membership->id, (string) $pharmacy->id, [
                'staff_role' => StaffRole::Pharmacist->value,
            ]);

            return $membership->refresh();
        });
    }

    public function recordPharmacistLicense(User $admin, PharmacyMembership $membership, string $licenseNumber): PharmacyMembership
    {
        PharmacyAccess::admin($admin);
        $licenseNumber = $this->blankToNull($licenseNumber);

        if ($licenseNumber === null) {
            throw new PharmacyRuleException('Enter the pharmacist license number.');
        }

        return DB::transaction(function () use ($admin, $membership, $licenseNumber): PharmacyMembership {
            $locked = PharmacyMembership::query()->whereKey($membership->id)->lockForUpdate()->first();

            if (! $locked instanceof PharmacyMembership
                || $locked->staff_role !== StaffRole::Pharmacist
                || $locked->status !== MembershipStatus::Active) {
                throw new PharmacyRuleException('Only an active pharmacist membership can record a license number.');
            }

            $locked->forceFill(['license_number' => $licenseNumber])->save();
            $this->audit->record($admin, 'pharmacist_license_recorded', 'pharmacy_membership', (string) $locked->id, (string) $locked->pharmacy_id);

            return $locked->refresh();
        });
    }

    public function verifyCredential(User $admin, PharmacyMembership $membership, string $evidence): void
    {
        PharmacyAccess::admin($admin);
        $evidence = trim($evidence);
        $expectedLicense = $this->blankToNull($membership->license_number);

        if ($evidence === '' || $expectedLicense === null || ! $this->pharmacistIsEligible($membership)) {
            throw new PharmacyRuleException('Credential verification requires an active pharmacist, a license number, and evidence notes.');
        }

        DB::transaction(function () use ($admin, $membership, $evidence, $expectedLicense): void {
            $locked = PharmacyMembership::query()->whereKey($membership->id)->lockForUpdate()->first();
            $currentLicense = $locked instanceof PharmacyMembership ? $this->blankToNull($locked->license_number) : null;

            if (! $locked instanceof PharmacyMembership
                || ! $this->pharmacistIsEligible($locked)
                || $currentLicense === null
                || $currentLicense !== $expectedLicense) {
                throw new PharmacyRuleException('The pharmacist record changed before it could be verified. Review the current membership.');
            }

            $locked->forceFill([
                'credential_status' => CredentialStatus::Verified,
                'credential_reviewed_by' => $admin->id,
                'credential_reviewed_at' => now(),
                'credential_verification_method' => 'documented_admin_review',
                'credential_evidence' => $evidence,
            ])->save();

            $this->audit->record($admin, 'pharmacist_credential_verified', 'pharmacy_membership', (string) $locked->id, (string) $locked->pharmacy_id, [
                'method' => 'documented_admin_review',
            ]);
        });
    }

    /**
     * @return Collection<int, PharmacyMembership>
     */
    public function verifiablePharmacistMemberships(Pharmacy $pharmacy): Collection
    {
        return PharmacyMembership::query()
            ->where('pharmacy_id', $pharmacy->id)
            ->where('staff_role', StaffRole::Pharmacist->value)
            ->where('status', MembershipStatus::Active->value)
            ->whereNotNull('license_number')
            ->whereRaw("btrim(license_number) <> ''")
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  list<PharmacyStatus>  $from
     */
    private function changeStatus(User $admin, Pharmacy $pharmacy, PharmacyStatus $to, ?string $reason, array $from, bool $invalidateSessions): void
    {
        PharmacyAccess::admin($admin);
        $reason = $reason === null ? null : trim($reason);

        if (in_array($to, [PharmacyStatus::Rejected, PharmacyStatus::Suspended], true) && ($reason === null || $reason === '')) {
            throw new PharmacyRuleException('This decision needs a reason.');
        }

        DB::transaction(function () use ($admin, $pharmacy, $to, $reason, $from, $invalidateSessions): void {
            $locked = $this->lockPharmacy($pharmacy);

            if (! in_array($locked->status, $from, true)) {
                throw new PharmacyRuleException('That pharmacy status change is not available.');
            }

            $locked->forceFill([
                'status' => $to,
                'status_reason' => $reason,
                'status_reviewed_by' => $admin->id,
                'status_reviewed_at' => now(),
            ])->save();

            if ($invalidateSessions) {
                $this->invalidateMembers($locked);
            }

            $this->audit->record($admin, 'pharmacy_status_changed', 'pharmacy', (string) $locked->id, (string) $locked->id, [
                'status' => $to->value,
            ]);
        });
    }

    private function pharmacistIsEligible(PharmacyMembership $membership): bool
    {
        return $membership->staff_role === StaffRole::Pharmacist
            && $membership->status === MembershipStatus::Active
            && $this->blankToNull($membership->license_number) !== null;
    }

    private function lockPharmacy(Pharmacy $pharmacy): Pharmacy
    {
        $locked = Pharmacy::query()->whereKey($pharmacy->id)->lockForUpdate()->first();

        if (! $locked instanceof Pharmacy) {
            throw new PharmacyRuleException('That pharmacy is no longer available.');
        }

        return $locked;
    }

    private function clearLicenseVerification(Pharmacy $pharmacy): void
    {
        $pharmacy->forceFill([
            'license_status' => LicenseStatus::Unverified,
            'license_reviewed_by' => null,
            'license_reviewed_at' => null,
            'license_verification_method' => null,
            'license_evidence' => null,
        ]);
    }

    private function clearLocationVerification(Pharmacy $pharmacy): void
    {
        $pharmacy->forceFill([
            'location_review_status' => LocationReviewStatus::Pending,
            'location_reviewed_by' => null,
            'location_reviewed_at' => null,
            'location_verification_method' => null,
            'location_evidence' => null,
        ]);
    }

    private function invalidateMembers(Pharmacy $pharmacy): void
    {
        $userIds = PharmacyMembership::query()
            ->where('pharmacy_id', $pharmacy->id)
            ->where('status', MembershipStatus::Active->value)
            ->pluck('user_id');

        if ($userIds->isEmpty()) {
            return;
        }

        DB::table('users')->whereIn('id', $userIds)->increment('session_generation');
    }

    private function faisalabad(): City
    {
        $city = City::query()
            ->where('country_code', 'PK')
            ->where('name', 'Faisalabad')
            ->where('is_active', true)
            ->first();

        if (! $city instanceof City) {
            throw new PharmacyRuleException('Faisalabad is not available.');
        }

        return $city;
    }

    private function blankToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function coordinatesDiffer(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left !== $right;
        }

        return abs((float) $left - (float) $right) > 0.0000005;
    }
}
