<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Prescription;
use App\Models\PrescriptionObjectHold;
use App\Prescriptions\PrescriptionErasureGate;
use App\Prescriptions\PrescriptionObjectStore;
use App\Prescriptions\PrescriptionWorkCursor;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class PrunePrescriptionObjects extends Command
{
    public const PRESCRIPTION_CURSOR = 'medifind.prescription_prune.prescriptions';

    public const HOLD_CURSOR = 'medifind.prescription_prune.holds';

    public const OBJECT_CURSOR = 'prescription_object_reconcile';

    protected $signature = 'prescriptions:prune-orphans';

    protected $description = 'Retry pending erasures and remove abandoned uploads. Does not apply a production retention period.';

    public function handle(PrescriptionObjectStore $objects): int
    {
        try {
            app(PrescriptionErasureGate::class)->assertReady();
        } catch (Throwable) {
            $this->error('Prescription storage cannot prove erasure.');
            Log::warning('prescription_bucket_erasure_rejected');

            return self::FAILURE;
        }

        $limit = max(1, (int) config('medifind.prescriptions.prune_attempt_limit'));
        $prescriptions = $this->walk(
            self::PRESCRIPTION_CURSOR,
            $limit,
            fn (?string $after, int $page, ?string $before): Collection => $this->prescriptionPage($after, $page, $before),
            fn (string $id): string => $this->purgePrescription($objects, $id),
        );
        $holds = $this->walk(
            self::HOLD_CURSOR,
            $limit,
            fn (?string $after, int $page, ?string $before): Collection => $this->holdPage($after, $page, $before),
            fn (string $id): string => $this->purgeHold($objects, $id),
        );
        $reconciled = $this->reconcileObjects($objects);
        $erased = $prescriptions + $holds + $reconciled['erased'];
        $pending = $this->pendingPrescriptions() + $this->pendingHolds() + $reconciled['pending'];

        $this->info('Erased '.$erased.' prescription objects.');

        if ($pending > 0) {
            $this->warn($pending.' prescription objects remain pending erasure.');
            Log::warning('prescription_object_purge_pending');
        }

        if (config('medifind.prescriptions.retention_days') === null) {
            $this->warn('PRESCRIPTION_RETENTION_DAYS is unset. Shared prescriptions are not deleted on a timer.');
        }

        return $pending > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Each eligible id is attempted at most once. The saved cursor makes the next
     * scheduled run continue past a persistently failing head, then wrap once.
     */
    private function walk(string $cacheKey, int $limit, callable $page, callable $handle): int
    {
        $cursor = PrescriptionWorkCursor::get($cacheKey);
        $attempted = 0;
        $erased = 0;
        $firstThisRun = null;
        $wrapped = false;

        while ($attempted < $limit) {
            $before = $wrapped ? $firstThisRun : null;
            $rows = $page($cursor, min(50, $limit - $attempted), $before);

            if (! $rows instanceof Collection || $rows->isEmpty()) {
                if ($wrapped || ($cursor === null && $firstThisRun === null)) {
                    break;
                }

                // The saved cursor is included once the tail is exhausted.
                // Leaving it out would pin a failing last id forever.
                $wrapped = true;
                $cursor = null;

                continue;
            }

            foreach ($rows as $row) {
                $id = (string) $row->id;

                if ($firstThisRun === null) {
                    $firstThisRun = $id;
                }

                $cursor = $id;
                $attempted++;

                if ($handle($id) === 'erased') {
                    $erased++;
                }

                if ($attempted >= $limit) {
                    break;
                }
            }
        }

        if (is_string($cursor) && $cursor !== '') {
            PrescriptionWorkCursor::put($cacheKey, $cursor);
        }

        return $erased;
    }

    /**
     * @return Collection<int, Prescription>
     */
    private function prescriptionPage(?string $after, int $limit, ?string $before): Collection
    {
        $query = Prescription::query()
            ->whereNotNull('deleted_at')
            ->where('object_state', '!=', 'purged')
            ->orderBy('id');

        if ($after !== null) {
            $query->where('id', '>', $after);
        }

        if ($before !== null) {
            $query->where('id', '<', $before);
        }

        return $query->limit($limit)->get(['id']);
    }

    /**
     * @return Collection<int, PrescriptionObjectHold>
     */
    private function holdPage(?string $after, int $limit, ?string $before): Collection
    {
        $cutoff = $this->cutoff();
        $query = PrescriptionObjectHold::query()
            ->where(function ($query) use ($cutoff): void {
                $query->where(function ($open) use ($cutoff): void {
                    $open->where('status', 'open')->where('created_at', '<=', $cutoff);
                })->orWhere('status', 'purge_pending');
            })
            ->orderBy('id');

        if ($after !== null) {
            $query->where('id', '>', $after);
        }

        if ($before !== null) {
            $query->where('id', '<', $before);
        }

        return $query->limit($limit)->get(['id']);
    }

    /**
     * The row lock is released before the storage call. A later transaction
     * records purged only when the same key is still eligible and unreferenced.
     */
    private function purgePrescription(PrescriptionObjectStore $objects, string $id): string
    {
        $key = DB::transaction(function () use ($id): ?string {
            $locked = Prescription::query()->whereKey($id)->lockForUpdate()->first();

            if (! $locked instanceof Prescription || $locked->deleted_at === null || $locked->object_state === 'purged') {
                return null;
            }

            return $locked->object_key;
        });

        if ($key === null || $this->liveReference($key)) {
            return 'skip';
        }

        if (! $this->confirmedDelete($objects, $key)) {
            return 'pending';
        }

        $marked = false;
        DB::transaction(function () use ($id, $key, &$marked): void {
            $locked = Prescription::query()->whereKey($id)->lockForUpdate()->first();

            if (! $locked instanceof Prescription || $locked->deleted_at === null || $locked->object_state === 'purged' || $locked->object_key !== $key || $this->liveReference($key)) {
                return;
            }

            $locked->forceFill(['object_state' => 'purged'])->save();
            PrescriptionObjectHold::query()->where('object_key', $key)->update(['status' => 'purged']);
            $marked = true;
        });

        return $marked ? 'erased' : 'pending';
    }

    /**
     * Claim the hold before storage I/O. attachHold only updates an open hold,
     * so an upload cannot commit a row for this object after the claim.
     * Absence is checked again after deletion, outside the row lock.
     */
    private function purgeHold(PrescriptionObjectStore $objects, string $id): string
    {
        $this->runTestingHook('before_hold_claim');

        $decision = DB::transaction(function () use ($id): ?array {
            $locked = PrescriptionObjectHold::query()->whereKey($id)->lockForUpdate()->first();

            if (! $locked instanceof PrescriptionObjectHold || ! $this->holdEligible($locked)) {
                return null;
            }

            if ($this->liveReference($locked->object_key)) {
                $locked->forceFill(['status' => 'attached'])->save();

                return null;
            }

            $fresh = $locked->status === 'open';

            if ($fresh) {
                $locked->forceFill(['status' => 'purge_pending'])->save();
            }

            return ['key' => $locked->object_key, 'fresh' => $fresh];
        });

        if ($decision === null) {
            return 'skip';
        }

        /** @var array{key: string, fresh: bool} $decision */
        $key = $decision['key'];
        $this->runTestingHook('after_hold_claim');
        $existed = $objects->exists($key);
        $deleted = $this->confirmedDelete($objects, $key);
        $this->runTestingHook('after_hold_delete');

        // Deleting a missing object on the same attempt that claimed an open
        // hold is not final: the upload PUT can still arrive. An object that
        // was present is purged only after a later absence check.
        if (! $deleted || $objects->exists($key) || ($decision['fresh'] && ! $existed)) {
            return 'pending';
        }

        $marked = false;
        DB::transaction(function () use ($id, $key, &$marked): void {
            $locked = PrescriptionObjectHold::query()->whereKey($id)->lockForUpdate()->first();

            if (! $locked instanceof PrescriptionObjectHold || $locked->object_key !== $key || $locked->status !== 'purge_pending' || $this->liveReference($key)) {
                return;
            }

            $locked->forceFill(['status' => 'purged'])->save();
            $marked = true;
        });

        return $marked ? 'erased' : 'pending';
    }

    private function runTestingHook(string $name): void
    {
        if (! app()->environment('testing')) {
            return;
        }

        $hook = config('medifind.prescriptions.testing_hooks.'.$name);

        if (is_callable($hook)) {
            $hook();
        }
    }

    private function liveReference(string $key): bool
    {
        return Prescription::query()->where('object_key', $key)->whereNull('deleted_at')->exists();
    }

    private function holdEligible(PrescriptionObjectHold $hold): bool
    {
        return $hold->status === 'purge_pending'
            || ($hold->status === 'open' && $hold->created_at->lessThanOrEqualTo($this->cutoff()));
    }

    private function pendingPrescriptions(): int
    {
        return Prescription::query()->whereNotNull('deleted_at')->where('object_state', '!=', 'purged')->count();
    }

    private function pendingHolds(): int
    {
        $cutoff = $this->cutoff();

        return PrescriptionObjectHold::query()
            ->where(function ($query) use ($cutoff): void {
                $query->where(function ($open) use ($cutoff): void {
                    $open->where('status', 'open')->where('created_at', '<=', $cutoff);
                })->orWhere('status', 'purge_pending');
            })
            ->count();
    }

    private function cutoff(): Carbon
    {
        return now()->subSeconds(max(1, (int) config('medifind.prescriptions.orphan_grace_seconds')));
    }

    /**
     * @return array{erased: int, pending: int}
     */
    private function reconcileObjects(PrescriptionObjectStore $objects): array
    {
        $limit = max(1, (int) config('medifind.prescriptions.prune_attempt_limit'));
        $cutoff = now()->subSeconds(max(1, (int) config('medifind.prescriptions.orphan_grace_seconds')))->getTimestamp();
        $cursor = $this->objectCursorKey(PrescriptionWorkCursor::get(self::OBJECT_CURSOR));
        $attempted = 0;
        $erased = 0;
        $pending = 0;
        $firstThisRun = null;
        $wrapped = false;

        while ($attempted < $limit) {
            try {
                $page = $objects->listPage($cursor, min(50, $limit - $attempted));
            } catch (Throwable) {
                Log::warning('prescription_object_list_failed');

                return ['erased' => $erased, 'pending' => $pending + 1];
            }

            if ($wrapped && is_string($firstThisRun)) {
                $page = array_values(array_filter(
                    $page,
                    fn (array $item): bool => strcmp($item['key'], $firstThisRun) < 0,
                ));
            }

            if ($page === []) {
                if ($wrapped || ($cursor === null && $firstThisRun === null)) {
                    break;
                }

                $wrapped = true;
                $cursor = null;

                continue;
            }

            foreach ($page as $item) {
                $key = $item['key'];

                if ($firstThisRun === null) {
                    $firstThisRun = $key;
                }

                $cursor = $key;
                $attempted++;

                if (! $this->objectReferenced($key)) {
                    if ($item['modified_at'] > 0 && $item['modified_at'] <= $cutoff) {
                        if ($objects->delete($key)) {
                            $erased++;
                        } else {
                            $pending++;
                        }
                    } elseif ($item['modified_at'] <= 0) {
                        $pending++;
                    }
                }

                if ($attempted >= $limit) {
                    break 2;
                }
            }
        }

        $position = is_string($cursor) ? $this->objectCursorPosition($cursor) : null;

        if ($position !== null) {
            PrescriptionWorkCursor::put(self::OBJECT_CURSOR, $position);
        }

        return ['erased' => $erased, 'pending' => $pending];
    }

    private function objectCursorKey(?string $position): ?string
    {
        if ($position === null || ! Str::isUuid($position)) {
            return null;
        }

        return 'rx/'.$position;
    }

    private function objectCursorPosition(string $key): ?string
    {
        if (! str_starts_with($key, 'rx/')) {
            return null;
        }

        $position = substr($key, 3);

        return Str::isUuid($position) ? $position : null;
    }

    private function objectReferenced(string $key): bool
    {
        if (Prescription::query()->where('object_key', $key)->where('object_state', '!=', 'purged')->exists()) {
            return true;
        }

        return PrescriptionObjectHold::query()->where('object_key', $key)->where('status', '!=', 'purged')->exists();
    }

    private function confirmedDelete(PrescriptionObjectStore $objects, string $key): bool
    {
        try {
            return $objects->delete($key);
        } catch (Throwable) {
            Log::warning('prescription_object_delete_failed');

            return false;
        }
    }
}
