<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $customer_id
 * @property string $key_hash
 * @property string $fingerprint
 * @property int $cart_version
 * @property string $order_id
 * @property Carbon $created_at
 */
class OrderSubmission extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'cart_version' => 'integer',
            'created_at' => 'datetime',
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
