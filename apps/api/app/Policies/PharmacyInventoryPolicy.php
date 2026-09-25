<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PharmacyStatus;
use App\Enums\UserRole;
use App\Models\Pharmacy;
use App\Models\PharmacyInventory;
use App\Models\User;
use App\Pharmacy\PharmacyAccess;
use App\Pharmacy\PharmacyRuleException;
use Filament\Facades\Filament;

final class PharmacyInventoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canEditTenant($user, $this->tenant());
    }

    public function view(User $user, PharmacyInventory $inventory): bool
    {
        return $this->canEditTenant($user, $inventory->pharmacy);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, PharmacyInventory $inventory): bool
    {
        return $this->view($user, $inventory);
    }

    public function delete(User $user, PharmacyInventory $inventory): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private function canEditTenant(User $user, ?Pharmacy $pharmacy): bool
    {
        if (! $user->isOperational() || ! $pharmacy instanceof Pharmacy || $pharmacy->status === PharmacyStatus::Suspended) {
            return false;
        }

        if ($user->role === UserRole::Admin) {
            return true;
        }

        try {
            PharmacyAccess::inventoryEditor($user, $pharmacy);

            return true;
        } catch (PharmacyRuleException) {
            return false;
        }
    }

    private function tenant(): ?Pharmacy
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Pharmacy ? $tenant : null;
    }
}
