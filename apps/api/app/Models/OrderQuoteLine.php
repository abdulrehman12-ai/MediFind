<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $quote_id
 * @property string $medicine_id
 * @property int $position
 * @property int $quantity
 * @property int $unit_price_paisa
 * @property int $line_total_paisa
 * @property string $generic_name
 * @property string|null $brand_name
 * @property string|null $strength
 * @property string $dosage_form
 * @property string $manufacturer
 */
class OrderQuoteLine extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => 'integer',
            'unit_price_paisa' => 'integer',
            'line_total_paisa' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<OrderQuote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(OrderQuote::class, 'quote_id');
    }
}
