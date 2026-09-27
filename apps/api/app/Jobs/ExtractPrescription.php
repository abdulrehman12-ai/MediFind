<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Prescriptions\CatalogMatcher;
use App\Prescriptions\Extraction\ExtractionResult;
use App\Prescriptions\Extraction\ExtractionTimeout;
use App\Prescriptions\Extraction\PrescriptionExtractor;
use App\Prescriptions\PrescriptionObjectStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class ExtractPrescription implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 20;

    private ?string $claimToken = null;

    public function __construct(
        public readonly string $prescriptionId,
        public readonly int $extractionVersion,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 15];
    }

    public function runNow(): void
    {
        app()->call([$this, 'handle']);
    }

    public function handle(PrescriptionExtractor $extractor, PrescriptionObjectStore $objects, CatalogMatcher $matcher): void
    {
        $token = $this->claim();

        if ($token === null) {
            return;
        }

        try {
            $this->afterClaim();
            $prescription = Prescription::query()->find($this->prescriptionId);

            if (! $prescription instanceof Prescription || $prescription->deleted_at !== null || $prescription->claim_token !== $token) {
                return;
            }

            $bytes = $objects->get($prescription->object_key);
            $result = $extractor->extract(hash('sha256', $bytes), $prescription->mime_type);
            $this->publish($token, $result, $matcher);
        } catch (ExtractionTimeout $exception) {
            $this->retryOrFail($token, $exception);
        } catch (Throwable $exception) {
            $this->retryOrFail($token, $exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->claimToken !== null) {
            $this->markFailed($this->claimToken, 'extraction_failed');
        }

        Log::warning('prescription_extraction_failed', [
            'failure_code' => 'extraction_failed',
        ]);
    }

    private function claim(): ?string
    {
        $token = (string) Str::uuid();
        $cutoff = now()->subSeconds(max(1, (int) config('medifind.prescriptions.claim_lease_seconds')));
        $claimed = Prescription::query()
            ->whereKey($this->prescriptionId)
            ->where('extraction_version', $this->extractionVersion)
            ->whereNull('deleted_at')
            ->where(function ($query) use ($cutoff): void {
                $query->where('extraction_state', 'queued')
                    ->orWhere(function ($expired) use ($cutoff): void {
                        $expired->where('extraction_state', 'processing')
                            ->where(function ($age) use ($cutoff): void {
                                $age->whereNull('claimed_at')->orWhere('claimed_at', '<=', $cutoff);
                            });
                    });
            })
            ->update([
                'extraction_state' => 'processing',
                'claim_token' => $token,
                'claimed_at' => now(),
            ]);

        if ($claimed !== 1) {
            return null;
        }

        $this->claimToken = $token;

        return $token;
    }

    private function publish(string $token, ExtractionResult $result, CatalogMatcher $matcher): void
    {
        DB::transaction(function () use ($token, $result, $matcher): void {
            $locked = Prescription::query()->whereKey($this->prescriptionId)->lockForUpdate()->first();

            if (! $locked instanceof Prescription
                || $locked->deleted_at !== null
                || $locked->extraction_version !== $this->extractionVersion
                || $locked->extraction_state !== 'processing'
                || $locked->claim_token !== $token) {
                return;
            }

            PrescriptionItem::query()
                ->where('prescription_id', $locked->id)
                ->where('extraction_version', $this->extractionVersion)
                ->delete();

            if ($result->failed) {
                $locked->forceFill([
                    'extraction_state' => 'failed',
                    'failure_code' => $result->failureCode ?? 'extraction_failed',
                    'claim_token' => null,
                    'claimed_at' => null,
                ])->save();

                return;
            }

            foreach ($result->lines as $index => $line) {
                $match = $matcher->match($line);
                $item = new PrescriptionItem;
                $item->forceFill([
                    'public_id' => (string) Str::uuid(),
                    'prescription_id' => $locked->id,
                    'extraction_version' => $this->extractionVersion,
                    'line_index' => $index + 1,
                    'extracted_text' => (string) ($line['text'] ?? ''),
                    'corrected_text' => null,
                    'match_status' => $match['status'],
                    'medicine_id' => $match['medicine_id'],
                    'customer_selected' => false,
                    'selected_medicine_id' => null,
                    'selected_fingerprint' => null,
                ]);
                $item->save();
            }

            $locked->forceFill([
                'extraction_state' => 'completed',
                'failure_code' => null,
                'claim_token' => null,
                'claimed_at' => null,
            ])->save();
        });
    }

    private function retryOrFail(string $token, Throwable $exception): void
    {
        if ($this->attempts() >= $this->tries) {
            $this->markFailed($token, $exception instanceof ExtractionTimeout ? 'extraction_timeout' : 'extraction_failed');

            return;
        }

        $released = Prescription::query()
            ->whereKey($this->prescriptionId)
            ->where('extraction_version', $this->extractionVersion)
            ->where('extraction_state', 'processing')
            ->where('claim_token', $token)
            ->whereNull('deleted_at')
            ->update([
                'extraction_state' => 'queued',
                'claim_token' => null,
                'claimed_at' => null,
            ]);

        if ($released !== 1) {
            return;
        }

        throw $exception;
    }

    private function markFailed(string $token, string $code): void
    {
        Prescription::query()
            ->whereKey($this->prescriptionId)
            ->where('extraction_version', $this->extractionVersion)
            ->where('claim_token', $token)
            ->whereNull('deleted_at')
            ->whereIn('extraction_state', ['queued', 'processing'])
            ->update([
                'extraction_state' => 'failed',
                'failure_code' => $code,
                'claim_token' => null,
                'claimed_at' => null,
            ]);
    }

    private function afterClaim(): void
    {
        if (! app()->environment('testing')) {
            return;
        }

        $hook = config('medifind.prescriptions.testing_hooks.after_claim');

        if (is_callable($hook)) {
            $hook();
        }
    }
}
