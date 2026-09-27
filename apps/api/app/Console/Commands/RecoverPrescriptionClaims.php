<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ExtractPrescription;
use App\Models\Prescription;
use App\Prescriptions\PrescriptionWorkCursor;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class RecoverPrescriptionClaims extends Command
{
    public const CURSOR_KEY = 'medifind.prescription_claim_cursor';

    protected $signature = 'prescriptions:recover-claims';

    protected $description = 'Queue a new attempt for prescription claims whose lease has expired.';

    public function handle(): int
    {
        $limit = max(1, (int) config('medifind.prescriptions.claim_recover_limit'));
        $cursor = PrescriptionWorkCursor::get(self::CURSOR_KEY);
        $queued = 0;
        $firstThisRun = null;
        $wrapped = false;

        while ($queued < $limit) {
            $before = $wrapped ? $firstThisRun : null;
            $rows = $this->eligible($cursor, min(50, $limit - $queued), $before);

            if ($rows->isEmpty()) {
                if ($wrapped || ($cursor === null && $firstThisRun === null)) {
                    break;
                }

                // Include the saved cursor after the tail is exhausted so the
                // last expired claim is not skipped on every later run.
                $wrapped = true;
                $cursor = null;

                continue;
            }

            foreach ($rows as $row) {
                $id = (string) $row->id;

                if ($firstThisRun === null) {
                    $firstThisRun = $id;
                }

                ExtractPrescription::dispatch($id, (int) $row->extraction_version);
                $cursor = $id;
                $queued++;

                if ($queued >= $limit) {
                    break;
                }
            }
        }

        if (is_string($cursor) && $cursor !== '') {
            PrescriptionWorkCursor::put(self::CURSOR_KEY, $cursor);
        }

        $this->info('Queued '.$queued.' expired prescription claims.');

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Prescription>
     */
    private function eligible(?string $after, int $limit, ?string $before): Collection
    {
        $cutoff = now()->subSeconds(max(1, (int) config('medifind.prescriptions.claim_lease_seconds')));

        $query = Prescription::query()
            ->where('extraction_state', 'processing')
            ->whereNull('deleted_at')
            ->where(function (Builder $query) use ($cutoff): void {
                $query->whereNull('claimed_at')->orWhere('claimed_at', '<=', $cutoff);
            })
            ->orderBy('id');

        if ($after !== null) {
            $query->where('id', '>', $after);
        }

        if ($before !== null) {
            $query->where('id', '<', $before);
        }

        return $query->limit($limit)->get(['id', 'extraction_version']);
    }
}
