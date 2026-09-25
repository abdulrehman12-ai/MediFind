<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LicenseStatus;
use App\Enums\LocationReviewStatus;
use App\Enums\PharmacyStatus;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property PharmacyStatus $status
 * @property LicenseStatus $license_status
 * @property LocationReviewStatus $location_review_status
 * @property string|null $license_number
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $license_verification_method
 * @property string|null $license_evidence
 * @property int|null $license_reviewed_by
 * @property Carbon|null $license_reviewed_at
 * @property string|null $location_verification_method
 * @property string|null $location_evidence
 * @property int|null $location_reviewed_by
 * @property Carbon|null $location_reviewed_at
 */
#[Hidden(['location'])]
class Pharmacy extends Model
{
    use HasUuids;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'status' => PharmacyStatus::class,
            'license_status' => LicenseStatus::class,
            'location_review_status' => LocationReviewStatus::class,
            'status_reviewed_at' => 'datetime',
            'appeal_submitted_at' => 'datetime',
            'license_reviewed_at' => 'datetime',
            'location_reviewed_at' => 'datetime',
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
        ];
    }

    public function publiclyEligible(): bool
    {
        return $this->status === PharmacyStatus::Approved
            && $this->license_status === LicenseStatus::Verified
            && $this->location_review_status === LocationReviewStatus::Approved;
    }

    /**
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * @return HasMany<PharmacyOperatingHour, $this>
     */
    public function operatingHours(): HasMany
    {
        return $this->hasMany(PharmacyOperatingHour::class);
    }

    /**
     * @return HasMany<PharmacyMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(PharmacyMembership::class);
    }

    /**
     * @return HasMany<PharmacyInventory, $this>
     */
    public function inventory(): HasMany
    {
        return $this->hasMany(PharmacyInventory::class);
    }
}
