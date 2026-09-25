<?php

declare(strict_types=1);

namespace App\Pharmacy;

use App\Audit\AuditRecorder;
use App\Enums\HoursIntervalKind;
use App\Models\Pharmacy;
use App\Models\PharmacyOperatingHour;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class OperatingHoursWriter
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  list<array{day_of_week: int, interval_kind: string, opens_at: ?string, closes_at: ?string}>  $intervals
     */
    public function replace(User $actor, Pharmacy $pharmacy, array $intervals): void
    {
        if ($actor->role->value === 'admin') {
            PharmacyAccess::admin($actor);
        } else {
            PharmacyAccess::manager($actor, $pharmacy);
        }

        WeeklyHours::assertValid($intervals);

        DB::transaction(function () use ($actor, $pharmacy, $intervals): void {
            PharmacyAccess::authorizeHoursChange($actor, $pharmacy);
            PharmacyOperatingHour::query()->where('pharmacy_id', $pharmacy->id)->delete();

            foreach ($intervals as $interval) {
                $row = new PharmacyOperatingHour;
                $row->forceFill([
                    'pharmacy_id' => $pharmacy->id,
                    'day_of_week' => $interval['day_of_week'],
                    'interval_kind' => HoursIntervalKind::from($interval['interval_kind']),
                    'opens_at' => $interval['opens_at'],
                    'closes_at' => $interval['closes_at'],
                ]);
                $row->save();
            }

            $this->audit->record($actor, 'pharmacy_hours_replaced', 'pharmacy', (string) $pharmacy->id, (string) $pharmacy->id, [
                'interval_count' => count($intervals),
            ]);
        });
    }
}
