<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StockStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PharmacyInventory extends Model
{
    use HasUuids;

    protected $table = 'pharmacy_inventory';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'stock_status' => StockStatus::class,
            'quantity_on_hand' => 'integer',
            'unit_price_paisa' => 'integer',
            'is_available_for_pickup' => 'boolean',
            'last_verified_at' => 'datetime',
            'version' => 'integer',
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
     * @return BelongsTo<Medicine, $this>
     */
    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }
}
