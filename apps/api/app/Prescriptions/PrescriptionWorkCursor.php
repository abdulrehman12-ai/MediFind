<?php

declare(strict_types=1);

namespace App\Prescriptions;

use Illuminate\Support\Facades\DB;

/**
 * Remembers the last maintenance id in the database. Cache expiry and a
 * process restart do not move the cursor back to a failing head.
 */
final class PrescriptionWorkCursor
{
    public static function get(string $name): ?string
    {
        $position = DB::table('prescription_work_cursors')->where('name', $name)->value('position');

        return is_string($position) && $position !== '' ? $position : null;
    }

    public static function put(string $name, string $position): void
    {
        if ($position === '') {
            return;
        }

        DB::table('prescription_work_cursors')->updateOrInsert(
            ['name' => $name],
            ['position' => $position, 'updated_at' => now()],
        );
    }
}
