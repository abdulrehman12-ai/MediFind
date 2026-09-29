<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderRequestMode;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $customer_id
 * @property string $pharmacy_id
 * @property OrderRequestMode $request_mode
 * @property string|null $prescription_id
 * @property string|null $customer_note
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Hidden(['customer_note'])]
class CustomerCart extends Model
{
    use HasUuids;

    protected $fillable = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'version' => 'integer',
            'request_mode' => OrderRequestMode::class,
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<CustomerCartItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CustomerCartItem::class, 'cart_id');
    }

    /**
     * @return BelongsTo<Pharmacy, $this>
     */
    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }
}
