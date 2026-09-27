<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $prescription_id
 * @property string $pharmacy_id
 * @property string $membership_id
 * @property int $reviewer_id
 * @property int $content_version
 * @property int $extraction_version
 * @property string $decision
 * @property string|null $reason
 */
class PrescriptionReview extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'content_version' => 'integer',
            'extraction_version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Prescription, $this>
     */
    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }
}
