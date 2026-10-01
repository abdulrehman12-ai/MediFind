<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $order_id
 * @property string $inventory_id
 * @property string $medicine_id
 * @property string|null $order_item_id
 * @property string|null $quote_line_id
 * @property int $quantity
 * @property string $state
 */
class OrderReservation extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }
}
