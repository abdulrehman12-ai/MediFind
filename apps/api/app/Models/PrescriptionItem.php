<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $public_id
 * @property string $prescription_id
 * @property int $extraction_version
 * @property int $line_index
 * @property string $extracted_text
 * @property string|null $corrected_text
 * @property string $match_status
 * @property string|null $medicine_id
 * @property bool $customer_selected
 * @property string|null $selected_medicine_id
 * @property string|null $selected_fingerprint
 */
class PrescriptionItem extends Model
{
    use HasUuids;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'extraction_version' => 'integer',
            'line_index' => 'integer',
            'extracted_text' => 'encrypted',
            'corrected_text' => 'encrypted',
            'customer_selected' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Prescription, $this>
     */
    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    /**
     * @return BelongsTo<Medicine, $this>
     */
    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }
}
