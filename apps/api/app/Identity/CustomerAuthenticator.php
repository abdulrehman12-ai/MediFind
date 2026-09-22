<?php

declare(strict_types=1);

namespace App\Identity;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Exceptions\ApiProblem;
use App\Identity\Profile\CustomerProfile;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class CustomerAuthenticator
{
    /**
     * @return array{access_token: string, token_type: string, expires_at: string, customer: array<string, mixed>}
     */
    public function establish(string $phoneE164): array
    {
        try {
            return DB::transaction(fn (): array => $this->login($phoneE164, true));
        } catch (UniqueConstraintViolationException) {
            return DB::transaction(fn (): array => $this->login($phoneE164, false));
        }
    }

    /**
     * @return array{access_token: string, token_type: string, expires_at: string, customer: array<string, mixed>}
     */
    private function login(string $phoneE164, bool $create): array
    {
        $user = User::query()->where('phone_e164', $phoneE164)->lockForUpdate()->first();

        if ($user === null) {
            if (! $create) {
                throw new RuntimeException('Customer could not be created.');
            }

            $user = new User;
            $user->forceFill([
                'public_id' => (string) Str::uuid(),
                'phone_e164' => $phoneE164,
                'phone_verified_at' => now(),
                'full_name' => null,
                'email' => null,
                'password' => null,
                'name' => null,
                'role' => UserRole::Customer,
                'status' => AccountStatus::Active,
                'last_login_at' => now(),
            ]);
            $user->save();
        } else {
            $this->assertEligible($user);
            $user->forceFill([
                'phone_verified_at' => $user->phone_verified_at ?? now(),
                'last_login_at' => now(),
            ]);
            $user->save();
        }

        $issued = $user->createToken('customer', ['customer:access'], now()->addDays(30));
        $expiresAt = $issued->accessToken->expires_at;

        return [
            'access_token' => $issued->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt !== null ? $expiresAt->toIso8601String() : now()->addDays(30)->toIso8601String(),
            'customer' => CustomerProfile::from($user),
        ];
    }

    private function assertEligible(User $user): void
    {
        if ($user->role !== UserRole::Customer) {
            throw new ApiProblem('account_role_forbidden', 'Customer login is not available for this phone number.', 403);
        }

        if ($user->status !== AccountStatus::Active) {
            throw new ApiProblem('account_inactive', 'This account is inactive.', 403);
        }
    }
}
