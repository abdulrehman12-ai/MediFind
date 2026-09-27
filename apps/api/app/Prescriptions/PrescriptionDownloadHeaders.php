<?php

declare(strict_types=1);

namespace App\Prescriptions;

final class PrescriptionDownloadHeaders
{
    /**
     * @return array<string, string>
     */
    public static function forMime(string $mime): array
    {
        $filename = $mime === 'application/pdf' ? 'prescription.pdf' : 'prescription';

        return [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }
}
