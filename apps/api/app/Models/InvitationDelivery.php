<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $account_invitation_id
 * @property string $purpose
 * @property string $token_ciphertext
 * @property string $token_hash
 * @property int $attempts
 * @property string|null $claim_id
 * @property Carbon|null $claimed_at
 */
class InvitationDelivery extends Model
{
    use HasUuids;

    protected $hidden = [
        'token_ciphertext',
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'claimed_at' => 'datetime',
        ];
    }
}
