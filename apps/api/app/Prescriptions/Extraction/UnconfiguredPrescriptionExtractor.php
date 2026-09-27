<?php

declare(strict_types=1);

namespace App\Prescriptions\Extraction;

/**
 * Refuses to read a prescription as if a live vision provider were approved.
 */
final class UnconfiguredPrescriptionExtractor implements PrescriptionExtractor
{
    public function extract(string $sha256, string $mime): ExtractionResult
    {
        return new ExtractionResult(true, 'provider_not_approved', []);
    }
}
