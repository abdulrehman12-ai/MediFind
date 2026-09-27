<?php

declare(strict_types=1);

namespace App\Prescriptions\Extraction;

/**
 * Deterministic local stand-in. It is not a vision model and does not call a network.
 */
final class SyntheticPrescriptionExtractor implements PrescriptionExtractor
{
    public function extract(string $sha256, string $mime): ExtractionResult
    {
        if ($mime === 'application/pdf') {
            return new ExtractionResult(true, 'pdf_not_extracted', []);
        }

        $fixtures = config('medifind.prescriptions.fixtures');
        $fixture = is_array($fixtures) ? ($fixtures[$sha256] ?? null) : null;

        if (! is_array($fixture)) {
            return new ExtractionResult(true, 'not_extracted', []);
        }

        $outcome = (string) ($fixture['outcome'] ?? 'lines');

        if ($outcome === 'empty') {
            return new ExtractionResult(false, null, []);
        }

        if ($outcome === 'fail') {
            return new ExtractionResult(true, 'extraction_failed', []);
        }

        if ($outcome === 'timeout') {
            throw new ExtractionTimeout;
        }

        $lines = $fixture['lines'] ?? [];

        if (! is_array($lines) || count($lines) > 20) {
            return new ExtractionResult(true, 'invalid_provider_output', []);
        }

        $clean = [];

        foreach ($lines as $line) {
            if (! is_array($line)) {
                return new ExtractionResult(true, 'invalid_provider_output', []);
            }

            $text = $this->limit($line['text'] ?? null);

            if ($text === null) {
                return new ExtractionResult(true, 'invalid_provider_output', []);
            }

            $clean[] = [
                'text' => $text,
                'generic_name' => $this->limit($line['generic_name'] ?? null),
                'brand_name' => $this->limit($line['brand_name'] ?? null),
                'strength' => $this->limit($line['strength'] ?? null),
                'dosage_form' => $this->limit($line['dosage_form'] ?? null),
                'manufacturer' => $this->limit($line['manufacturer'] ?? null),
            ];
        }

        return new ExtractionResult(false, null, $clean);
    }

    private function limit(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '' || mb_strlen($trimmed) > 180) {
            return null;
        }

        return $trimmed;
    }
}
