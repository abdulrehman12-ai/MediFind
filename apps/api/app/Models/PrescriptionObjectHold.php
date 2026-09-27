<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $object_key
 * @property string $status
 * @property Carbon $created_at
 */
class PrescriptionObjectHold extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
