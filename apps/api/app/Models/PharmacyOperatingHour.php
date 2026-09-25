<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HoursIntervalKind;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $day_of_week
 * @property HoursIntervalKind $interval_kind
 * @property string|null $opens_at
 * @property string|null $closes_at
 */
class PharmacyOperatingHour extends Model
{
    use HasUuids;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'interval_kind' => HoursIntervalKind::class,
        ];
    }

    /**
     * @return BelongsTo<Pharmacy, $this>
     */
    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }
}
