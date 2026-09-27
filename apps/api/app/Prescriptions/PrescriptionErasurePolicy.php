<?php

declare(strict_types=1);

namespace App\Prescriptions;

use RuntimeException;

/**
 * Deletion is erasure only on an unversioned bucket with Object Lock disabled.
 * A missing current object does not erase older versions or a locked object.
 */
final class PrescriptionErasurePolicy
{
    public function assertBucketAllowsErasure(?string $versioningStatus, bool $objectLockEnabled): void
    {
        if ($versioningStatus !== null) {
            throw new RuntimeException('Prescription erasure requires an unversioned bucket.');
        }

        if ($objectLockEnabled) {
            throw new RuntimeException('Prescription erasure requires Object Lock to be disabled.');
        }
    }
}
