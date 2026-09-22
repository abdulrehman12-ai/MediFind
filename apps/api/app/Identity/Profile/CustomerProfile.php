<?php

declare(strict_types=1);

namespace App\Identity\Profile;

use App\Identity\Phone\MaskedPhone;
use App\Models\User;

final class CustomerProfile
{
    /**
     * @return array<string, mixed>
     */
    public static function from(User $user): array
    {
        return [
            'id' => $user->public_id,
            'phone_masked' => MaskedPhone::fromE164((string) $user->phone_e164),
            'phone_verified_at' => $user->phone_verified_at?->toIso8601String(),
            'full_name' => $user->full_name,
            'email' => $user->email,
            'role' => $user->role->value,
            'status' => $user->status->value,
        ];
    }
}
