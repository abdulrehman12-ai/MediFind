<?php

declare(strict_types=1);

namespace App\Orders;

use App\Enums\CredentialStatus;
use App\Enums\MembershipStatus;
use App\Enums\PharmacyStatus;
use App\Enums\StaffRole;
use App\Exceptions\ApiProblem;
use App\Models\Pharmacy;
use App\Models\PharmacyMembership;
use App\Models\User;

final class FulfillmentAccess
{
    public function lock(User $pharmacist, Pharmacy $pharmacy): PharmacyMembership
    {
        $lockedPharmacy = Pharmacy::query()->whereKey($pharmacy->id)->lockForUpdate()->first();
        $lockedUser = User::withTrashed()->whereKey($pharmacist->id)->lockForUpdate()->first();

        if (! $lockedPharmacy instanceof Pharmacy || ! $lockedUser instanceof User || ! $lockedUser->isOperational()) {
            throw new ApiProblem('forbidden', 'This action is unauthorized.', 403);
        }

        if ($lockedPharmacy->status !== PharmacyStatus::Approved || ! $lockedPharmacy->publiclyEligible()) {
            throw new ApiProblem('forbidden', 'This action is unauthorized.', 403);
        }

        $membership = PharmacyMembership::query()
            ->where('pharmacy_id', $lockedPharmacy->id)
            ->where('user_id', $lockedUser->id)
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
}
