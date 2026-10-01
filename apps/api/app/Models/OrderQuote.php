<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $order_id
 * @property int $version
 * @property string $state
 * @property int $total_paisa
 * @property int|null $prescription_content_version
 * @property int $created_by
 * @property Carbon $expires_at
 * @property Carbon $created_at
 */
class OrderQuote extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'total_paisa' => 'integer',
            'prescription_content_version' => 'integer',
            'created_by' => 'integer',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<OrderQuoteLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderQuoteLine::class, 'quote_id')->orderBy('position');
    }

    /**
     * @return HasOne<OrderQuoteAcceptance, $this>
     */
    public function acceptance(): HasOne
    {
        return $this->hasOne(OrderQuoteAcceptance::class, 'quote_id');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
