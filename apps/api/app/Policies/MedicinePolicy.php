<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Medicine;
use App\Models\User;

final class MedicinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOperational() && in_array($user->role, [UserRole::Admin, UserRole::PharmacyStaff], true);
    }

    public function view(User $user, Medicine $medicine): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Admin && $user->isOperational();
    }

    public function update(User $user, Medicine $medicine): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, Medicine $medicine): bool
    {
        return false;
    }
}
