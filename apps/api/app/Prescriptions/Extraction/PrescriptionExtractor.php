<?php

declare(strict_types=1);

namespace App\Prescriptions\Extraction;

interface PrescriptionExtractor
{
    public function extract(string $sha256, string $mime): ExtractionResult;
}
