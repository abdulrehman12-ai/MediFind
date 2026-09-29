<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $order_id
 * @property OrderStatus $status
 * @property string $event
 * @property string|null $cause
 * @property int|null $actor_id
 * @property string|null $reason
 * @property Carbon $created_at
 */
class OrderStatusEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'actor_id' => 'integer',
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
