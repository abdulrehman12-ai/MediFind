<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\MedicineUse;
use App\Models\User;

final class MedicineUsePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Admin && $user->isOperational();
    }

    public function view(User $user, MedicineUse $use): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, MedicineUse $use): bool
    {
        return $this->viewAny($user) && $use->review_status === 'draft';
    }

    public function delete(User $user, MedicineUse $use): bool
    {
        return false;
    }
}
