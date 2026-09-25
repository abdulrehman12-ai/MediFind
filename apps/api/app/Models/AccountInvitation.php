<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvitationDeliveryStatus;
use App\Enums\InvitationKind;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $email
 * @property InvitationKind $kind
 * @property string|null $pharmacy_id
 * @property string $token_hash
 * @property InvitationDeliveryStatus $delivery_status
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $superseded_at
 */
class AccountInvitation extends Model
{
    public const UPDATED_AT = null;

    use HasUuids;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'kind' => InvitationKind::class,
            'delivery_status' => InvitationDeliveryStatus::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Pharmacy, $this>
     */
    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class);
    }
}
