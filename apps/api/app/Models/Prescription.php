<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $public_id
 * @property int $customer_id
 * @property string $object_key
 * @property string $display_name
 * @property string $mime_type
 * @property int $byte_size
 * @property string $sha256
 * @property string $extraction_state
 * @property int $extraction_version
 * @property int $content_version
 * @property string $share_state
 * @property string|null $share_mode
 * @property int|null $shared_content_version
 * @property string|null $pharmacy_id
 * @property string|null $failure_code
 * @property string $object_state
 * @property string|null $claim_token
 * @property Carbon|null $claimed_at
 * @property Carbon|null $deleted_at
 */
class Prescription extends Model
{
    use HasUuids;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'extraction_version' => 'integer',
            'content_version' => 'integer',
            'shared_content_version' => 'integer',
            'claimed_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /**
     * @return BelongsTo<Pharmacy, $this>
     */
    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }

    /**
     * @return HasMany<PrescriptionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PrescriptionItem::class);
    }

    /**
     * @return HasMany<PrescriptionReview, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(PrescriptionReview::class);
    }
}
