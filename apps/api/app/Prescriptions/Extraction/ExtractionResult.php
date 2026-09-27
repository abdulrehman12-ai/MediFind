<?php

declare(strict_types=1);

namespace App\Prescriptions\Extraction;

final class ExtractionResult
{
    /**
     * @param  list<array{generic_name?: string|null, brand_name?: string|null, strength?: string|null, dosage_form?: string|null, manufacturer?: string|null, text?: string|null}>  $lines
     */
    public function __construct(
        public readonly bool $failed,
        public readonly ?string $failureCode,
        public readonly array $lines,
    ) {}
}
