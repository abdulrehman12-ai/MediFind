<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $cart_id
 * @property string $medicine_id
 * @property int $quantity
 */
class CustomerCartItem extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CustomerCart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(CustomerCart::class, 'cart_id');
    }

    /**
     * @return BelongsTo<Medicine, $this>
     */
    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }
}
