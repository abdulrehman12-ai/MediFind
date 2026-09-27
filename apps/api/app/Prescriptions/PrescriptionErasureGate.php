<?php

declare(strict_types=1);

namespace App\Prescriptions;

final class PrescriptionErasureGate
{
    public function __construct(
        private readonly PrescriptionStorageGuard $guard,
        private readonly PrescriptionErasurePolicy $policy,
        private readonly ObjectBucketMetadata $metadata,
    ) {}

    public function assertReady(): void
    {
        $this->guard->assertConfigured();
        $name = (string) config('medifind.prescriptions.disk');
        $driver = (string) config('filesystems.disks.'.$name.'.driver');

        if ($driver !== 's3') {
            return;
        }

        $bucket = trim((string) config('filesystems.disks.'.$name.'.bucket'));
        $this->policy->assertBucketAllowsErasure(
            $this->metadata->versioningStatus($bucket),
            $this->metadata->objectLockEnabled($bucket),
        );
    }
}
