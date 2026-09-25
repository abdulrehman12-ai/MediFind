<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PharmacyStatus;
use App\Enums\UserRole;
use App\Models\Pharmacy;
use App\Models\User;
use App\Pharmacy\PharmacyAccess;
use App\Pharmacy\PharmacyRuleException;

final class PharmacyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOperational() && in_array($user->role, [UserRole::Admin, UserRole::PharmacyStaff], true);
    }

    public function view(User $user, Pharmacy $pharmacy): bool
    {
        if ($user->role === UserRole::Admin) {
            return $user->isOperational();
        }

        return $user->canAccessTenant($pharmacy);
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Admin && $user->isOperational();
    }

    public function update(User $user, Pharmacy $pharmacy): bool
    {
        if (! $this->view($user, $pharmacy) || $pharmacy->status === PharmacyStatus::Suspended) {
            return false;
        }

        if ($user->role === UserRole::Admin) {
            return true;
        }

        try {
            PharmacyAccess::manager($user, $pharmacy);

            return true;
        } catch (PharmacyRuleException) {
            return false;
        }
    }

    public function delete(User $user, Pharmacy $pharmacy): bool
    {
        return false;
    }
}
