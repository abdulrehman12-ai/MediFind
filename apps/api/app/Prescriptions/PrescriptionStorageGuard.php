<?php

declare(strict_types=1);

namespace App\Prescriptions;

use App\Exceptions\ApiProblem;

/**
 * Prescription bytes use Amazon S3 outside tests. The testing disk stays local.
 */
final class PrescriptionStorageGuard
{
    public function assertConfigured(): void
    {
        $name = (string) config('medifind.prescriptions.disk');
        $disk = config('filesystems.disks.'.$name);

        if (! is_array($disk)) {
            $this->fail('Prescription storage is not configured.');
        }

        if ($name === 'prescriptions_testing') {
            if (! app()->environment('testing') || ($disk['driver'] ?? '') !== 'local') {
                $this->fail('Prescription storage must use Amazon S3.');
            }

            return;
        }

        if ($name !== 'prescriptions' || ($disk['driver'] ?? '') !== 's3') {
            $this->fail('Prescription storage must use Amazon S3.');
        }

        if (trim((string) ($disk['endpoint'] ?? '')) !== '' || trim((string) ($disk['url'] ?? '')) !== '') {
            $this->fail('Prescription storage must use the AWS regional endpoint.');
        }

        if (array_key_exists('use_path_style_endpoint', $disk) && $disk['use_path_style_endpoint'] !== false) {
            $this->fail('Prescription storage must use the AWS regional endpoint.');
        }

        if (trim((string) ($disk['bucket'] ?? '')) === '' || trim((string) ($disk['region'] ?? '')) === '') {
            $this->fail('PRESCRIPTION_BUCKET and PRESCRIPTION_REGION are required.');
        }
    }

    private function fail(string $message): never
    {
        throw new ApiProblem('storage_unavailable', $message, 503);
    }
}
