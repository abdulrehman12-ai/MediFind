<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $order_id
 * @property string $medicine_id
 * @property string $generic_name
 * @property string|null $brand_name
 * @property string|null $strength
 * @property string $dosage_form
 * @property string $manufacturer
 * @property bool $prescription_required
 * @property int $quantity
 * @property int $unit_price_paisa
 * @property int $line_estimate_paisa
 * @property string $inventory_id
 * @property int $inventory_version
 * @property Carbon $inventory_verified_at
 */
class OrderItem extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'prescription_required' => 'boolean',
            'quantity' => 'integer',
            'unit_price_paisa' => 'integer',
            'line_estimate_paisa' => 'integer',
            'inventory_version' => 'integer',
            'inventory_verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
