<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    use HasUuids;

    protected $fillable = [
        'actor_id',
        'action',
        'subject_type',
        'subject_id',
        'pharmacy_id',
        'metadata',
        'request_id',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
