<?php

declare(strict_types=1);

namespace App\Prescriptions;

use Aws\S3\Exception\S3Exception;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class S3ObjectBucketMetadata implements ObjectBucketMetadata
{
    public function versioningStatus(string $bucket): ?string
    {
        try {
            $result = $this->client()->getBucketVersioning(['Bucket' => $bucket]);
        } catch (Throwable) {
            throw new RuntimeException('Prescription bucket versioning could not be read.');
        }

        if (! is_array($result) && ! $result instanceof \ArrayAccess) {
            throw new RuntimeException('Prescription bucket versioning could not be read.');
        }

        if (is_array($result) && array_is_list($result) && $result !== []) {
            throw new RuntimeException('Prescription bucket versioning could not be read.');
        }

        $present = is_array($result)
            ? array_key_exists('Status', $result)
            : $result->offsetExists('Status');

        if (! $present) {
            return null;
        }

        $status = $result['Status'];

        if (! is_string($status) || $status === '') {
            throw new RuntimeException('Prescription bucket versioning could not be read.');
        }

        return $status;
    }

    public function objectLockEnabled(string $bucket): bool
    {
        try {
            $result = $this->client()->getObjectLockConfiguration(['Bucket' => $bucket]);
        } catch (S3Exception $exception) {
            if ($exception->getAwsErrorCode() === 'ObjectLockConfigurationNotFoundError') {
                return false;
            }

            throw new RuntimeException('Prescription bucket lock configuration could not be read.');
        } catch (Throwable) {
            throw new RuntimeException('Prescription bucket lock configuration could not be read.');
        }

        $enabled = $result['ObjectLockConfiguration']['ObjectLockEnabled'] ?? null;

        if ($enabled === 'Enabled') {
            return true;
        }

        throw new RuntimeException('Prescription bucket lock configuration is not recognized.');
    }

    private function client(): object
    {
        $disk = Storage::disk((string) config('medifind.prescriptions.disk'));

        if (! method_exists($disk, 'getClient')) {
            throw new RuntimeException('Prescription storage is not an S3 client.');
        }

        return $disk->getClient();
    }
}
