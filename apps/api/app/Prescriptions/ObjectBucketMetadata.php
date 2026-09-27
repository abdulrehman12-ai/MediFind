<?php

declare(strict_types=1);

namespace App\Prescriptions;

interface ObjectBucketMetadata
{
    public function versioningStatus(string $bucket): ?string;

    public function objectLockEnabled(string $bucket): bool;
}
