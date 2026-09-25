<?php

declare(strict_types=1);

namespace App\Pharmacy;

use App\Enums\InvitationKind;
use App\Enums\MembershipStatus;
use App\Enums\PharmacyStatus;
use App\Enums\StaffRole;
use App\Enums\UserRole;
use App\Models\Pharmacy;
use App\Models\PharmacyMembership;
use App\Models\User;

final class PharmacyAccess
{
    public static function admin(User $user): void
    {
        if ($user->role !== UserRole::Admin || ! $user->isOperational()) {
            throw new PharmacyRuleException('This action requires an active administrator.');
        }
    }

    public static function membership(User $user, Pharmacy $pharmacy): PharmacyMembership
    {
        if ($user->role !== UserRole::PharmacyStaff || ! $user->isOperational()) {
            throw new PharmacyRuleException('This action requires an active pharmacy staff account.');
        }

        $membership = PharmacyMembership::query()
            ->where('pharmacy_id', $pharmacy->id)
            ->where('user_id', $user->id)
            ->where('status', MembershipStatus::Active->value)
            ->first();

        if (! $membership instanceof PharmacyMembership) {
            throw new PharmacyRuleException('You are not an active member of this pharmacy.');
        }

        if ($pharmacy->status === PharmacyStatus::Suspended) {
            throw new PharmacyRuleException('This pharmacy is suspended.');
        }

        return $membership;
    }

    public static function manager(User $user, Pharmacy $pharmacy): PharmacyMembership
    {
        $membership = self::membership($user, $pharmacy);

        if ($membership->staff_role !== StaffRole::Manager) {
            throw new PharmacyRuleException('This action requires the pharmacy manager.');
        }

        return $membership;
    }

    public static function inventoryEditor(User $user, Pharmacy $pharmacy): PharmacyMembership
    {
        $membership = self::membership($user, $pharmacy);
        $allowed = [StaffRole::Manager, StaffRole::Pharmacist, StaffRole::InventoryStaff];

        if (! in_array($membership->staff_role, $allowed, true)) {
            throw new PharmacyRuleException('You cannot edit this pharmacy inventory.');
        }

        return $membership;
    }

    public static function authorizeInventoryChange(User $actor, Pharmacy $pharmacy): void
    {
        $pharmacy = self::lockedPharmacy($pharmacy);
        $actor = self::lockedUser($actor);

        if ($actor->role === UserRole::Admin) {
            self::admin($actor);

            return;
        }

        $membership = self::lockedMembership($actor, $pharmacy);
        $allowed = [StaffRole::Manager, StaffRole::Pharmacist, StaffRole::InventoryStaff];

        if (! in_array($membership->staff_role, $allowed, true)) {
            throw new PharmacyRuleException('You cannot edit this pharmacy inventory.');
        }
    }

    public static function authorizeHoursChange(User $actor, Pharmacy $pharmacy): void
    {
        $pharmacy = self::lockedPharmacy($pharmacy);
        $actor = self::lockedUser($actor);

        if ($actor->role === UserRole::Admin) {
            self::admin($actor);

            return;
        }

        $membership = self::lockedMembership($actor, $pharmacy);

        if ($membership->staff_role !== StaffRole::Manager) {
            throw new PharmacyRuleException('This action requires the pharmacy manager.');
        }
    }

    public static function authorizeInvitation(User $actor, InvitationKind $kind, ?Pharmacy $pharmacy): void
    {
        $lockedPharmacy = $pharmacy instanceof Pharmacy ? self::lockedPharmacy($pharmacy) : null;
        $actor = self::lockedUser($actor);

        if ($kind === InvitationKind::InventoryStaff && $actor->role !== UserRole::Admin) {
            if (! $lockedPharmacy instanceof Pharmacy) {
                throw new PharmacyRuleException('This invitation needs a pharmacy.');
            }

            $membership = self::lockedMembership($actor, $lockedPharmacy);

            if ($membership->staff_role !== StaffRole::Manager) {
                throw new PharmacyRuleException('This action requires the pharmacy manager.');
            }

            if (! in_array($lockedPharmacy->status, [PharmacyStatus::Pending, PharmacyStatus::Approved], true)) {
                throw new PharmacyRuleException('Staff invitations are available while the pharmacy is pending or approved.');
            }

            return;
        }

        self::admin($actor);
    }

    private static function lockedPharmacy(Pharmacy $pharmacy): Pharmacy
    {
        $locked = Pharmacy::query()->whereKey($pharmacy->id)->lockForUpdate()->first();

        if (! $locked instanceof Pharmacy) {
            throw new PharmacyRuleException('That pharmacy is no longer available.');
        }

        return $locked;
    }

    private static function lockedUser(User $actor): User
    {
        $locked = User::withTrashed()->whereKey($actor->id)->lockForUpdate()->first();

        if (! $locked instanceof User) {
            throw new PharmacyRuleException('This action requires an active account.');
        }

        return $locked;
    }

    private static function lockedMembership(User $user, Pharmacy $pharmacy): PharmacyMembership
    {
        if ($user->role !== UserRole::PharmacyStaff || ! $user->isOperational()) {
            throw new PharmacyRuleException('This action requires an active pharmacy staff account.');
        }

        $membership = PharmacyMembership::query()
            ->where('pharmacy_id', $pharmacy->id)
            ->where('user_id', $user->id)
            ->where('status', MembershipStatus::Active->value)
            ->lockForUpdate()
            ->first();

        if (! $membership instanceof PharmacyMembership) {
            throw new PharmacyRuleException('You are not an active member of this pharmacy.');
        }

        if ($pharmacy->status === PharmacyStatus::Suspended) {
            throw new PharmacyRuleException('This pharmacy is suspended.');
        }

        return $membership;
    }
}
