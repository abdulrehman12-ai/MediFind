<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderRequestMode;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $public_id
 * @property string $reference
 * @property int $customer_id
 * @property string $pharmacy_id
 * @property OrderRequestMode $request_mode
 * @property string|null $prescription_id
 * @property string|null $prescription_public_id
 * @property int|null $prescription_content_version
 * @property string|null $prescription_share_mode
 * @property bool $prescription_usable
 * @property string|null $customer_note
 * @property OrderStatus $status
 * @property string $payment_method
 * @property string $payment_status
 * @property int|null $estimate_total_paisa
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Hidden(['customer_note'])]
class Order extends Model
{
    use HasUuids;

    protected $fillable = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'prescription_content_version' => 'integer',
            'prescription_usable' => 'boolean',
            'estimate_total_paisa' => 'integer',
            'request_mode' => OrderRequestMode::class,
            'status' => OrderStatus::class,
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('medicine_id');
    }

    /**
     * @return HasMany<OrderStatusEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(OrderStatusEvent::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }
}
