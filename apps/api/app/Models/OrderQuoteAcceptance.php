<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $quote_id
 * @property string $order_id
 * @property int $customer_id
 * @property int $quote_version
 * @property int $total_paisa
 * @property int|null $prescription_content_version
 * @property string $line_fingerprint
 * @property Carbon $accepted_at
 */
class OrderQuoteAcceptance extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'quote_version' => 'integer',
            'total_paisa' => 'integer',
            'prescription_content_version' => 'integer',
            'accepted_at' => 'datetime',
        ];
    }
}
