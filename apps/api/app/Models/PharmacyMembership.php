<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CredentialStatus;
use App\Enums\MembershipStatus;
use App\Enums\StaffRole;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $pharmacy_id
 * @property StaffRole $staff_role
 * @property MembershipStatus $status
 * @property string|null $license_number
 * @property CredentialStatus $credential_status
 * @property int|null $credential_reviewed_by
 * @property Carbon|null $credential_reviewed_at
 * @property string|null $credential_verification_method
 * @property string|null $credential_evidence
 */
class PharmacyMembership extends Model
{
    use HasUuids;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'staff_role' => StaffRole::class,
            'status' => MembershipStatus::class,
            'credential_status' => CredentialStatus::class,
            'credential_reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Pharmacy, $this>
     */
    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function credentialReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'credential_reviewed_by');
    }
}
