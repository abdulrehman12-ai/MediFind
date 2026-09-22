<?php

declare(strict_types=1);

namespace App\Identity;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AccountStatusService
{
    public function suspend(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->status = AccountStatus::Suspended;
            $user->save();
            $user->tokens()->delete();
        });
    }

    public function reactivate(User $user): void
    {
        $user->status = AccountStatus::Active;
        $user->save();
    }
}
