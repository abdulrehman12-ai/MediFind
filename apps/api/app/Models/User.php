<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\MembershipStatus;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property string $public_id
 * @property string|null $phone_e164
 * @property Carbon|null $phone_verified_at
 * @property string|null $full_name
 * @property string|null $email
 * @property UserRole $role
 * @property AccountStatus $status
 * @property Carbon|null $last_login_at
 * @property Carbon|null $email_verified_at
 * @property int $session_generation
 */
#[Fillable(['full_name', 'email'])]
#[Hidden(['id', 'phone_e164', 'password', 'remember_token', 'name', 'app_authentication_secret', 'app_authentication_recovery_codes'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasName, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, Notifiable, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => AccountStatus::class,
            'session_generation' => 'integer',
        ];
    }

    /**
     * @return HasMany<UserAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(UserAddress::class);
    }

    public function getFilamentName(): string
    {
        $name = trim((string) $this->full_name);

        if ($name !== '') {
            return $name;
        }

        $email = trim((string) $this->email);

        return $email !== '' ? $email : 'MediFind user';
    }

    /**
     * @return HasMany<Prescription, $this>
     */
    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class, 'customer_id');
    }

    public function isOperational(): bool
    {
        return $this->status === AccountStatus::Active && $this->deleted_at === null;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->isOperational()) {
            return false;
        }

        return match ($panel->getId()) {
            'admin' => $this->role === UserRole::Admin,
            'pharmacy' => $this->role === UserRole::PharmacyStaff,
            default => false,
        };
    }

    /**
     * @return array<int, Pharmacy>|Collection<int, Pharmacy>
     */
    public function getTenants(Panel $panel): array|Collection
    {
        if ($panel->getId() !== 'pharmacy') {
            return collect();
        }

        $pharmacyIds = PharmacyMembership::query()
            ->where('user_id', $this->id)
            ->where('status', MembershipStatus::Active->value)
            ->pluck('pharmacy_id');

        return Pharmacy::query()->whereIn('id', $pharmacyIds)->orderBy('name')->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        if (! $tenant instanceof Pharmacy) {
            return false;
        }

        return PharmacyMembership::query()
            ->where('pharmacy_id', $tenant->getKey())
            ->where('user_id', $this->id)
            ->where('status', MembershipStatus::Active->value)
            ->exists();
    }
}
