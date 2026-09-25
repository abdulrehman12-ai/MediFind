<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MedicineStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property MedicineStatus $status
 * @property bool $prescription_required
 * @property bool $controlled_drug
 * @property string $normalized_generic_name
 * @property string $normalized_strength
 * @property string $normalized_dosage_form
 * @property string $normalized_manufacturer
 */
class Medicine extends Model
{
    use HasUuids;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'prescription_required' => 'boolean',
            'controlled_drug' => 'boolean',
            'status' => MedicineStatus::class,
        ];
    }

    public function identityIsComplete(): bool
    {
        return $this->normalized_generic_name !== ''
            && $this->normalized_strength !== ''
            && $this->normalized_dosage_form !== ''
            && $this->normalized_manufacturer !== '';
    }

    /**
     * @return HasMany<MedicineAlias, $this>
     */
    public function aliases(): HasMany
    {
        return $this->hasMany(MedicineAlias::class);
    }

    /**
     * @return HasMany<MedicineUse, $this>
     */
    public function uses(): HasMany
    {
        return $this->hasMany(MedicineUse::class);
    }
}
