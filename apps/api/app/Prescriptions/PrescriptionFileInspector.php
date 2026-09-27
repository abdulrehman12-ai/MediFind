<?php

declare(strict_types=1);

namespace App\Prescriptions;

use App\Exceptions\ApiProblem;
use Illuminate\Http\UploadedFile;

final class PrescriptionFileInspector
{
    public const MAX_BYTES = 8388608;

    public const MAX_EDGE = 8000;

    public const MAX_PIXELS = 4000000;

    public function __construct(
        private readonly ImageStructureValidator $images = new ImageStructureValidator,
        private readonly PdfStructureValidator $pdfs = new PdfStructureValidator,
    ) {}

    public function read(UploadedFile $file): string
    {
        $reported = $file->getSize();

        if (is_int($reported) && ($reported < 1 || $reported > self::MAX_BYTES)) {
            throw new ApiProblem('invalid_prescription_file', 'The prescription file is empty or larger than 8 MB.', 422);
        }

        $path = $file->getRealPath();

        if (! is_string($path) || $path === '') {
            throw new ApiProblem('invalid_prescription_file', 'The prescription file could not be read.', 422);
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new ApiProblem('invalid_prescription_file', 'The prescription file could not be read.', 422);
        }

        try {
            $bytes = '';
            $remaining = self::MAX_BYTES + 1;

            while ($remaining > 0 && ! feof($handle)) {
                $chunk = fread($handle, min(8192, $remaining));

                if (! is_string($chunk)) {
                    throw new ApiProblem('invalid_prescription_file', 'The prescription file could not be read.', 422);
                }

                if ($chunk === '') {
                    break;
                }

                $bytes .= $chunk;
                $remaining -= strlen($chunk);
            }
        } finally {
            fclose($handle);
        }

        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new ApiProblem('invalid_prescription_file', 'The prescription file is empty or larger than 8 MB.', 422);
        }

        return $bytes;
    }

    /**
     * @return array{mime: string, extension: string}
     */
    public function inspect(string $bytes): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new ApiProblem('invalid_prescription_file', 'The prescription file is empty or larger than 8 MB.', 422);
        }

        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $mime = is_string($detected) ? $detected : '';

        if ($mime === 'application/pdf' || str_starts_with($bytes, '%PDF-')) {
            if ($mime !== 'application/pdf' || ! str_starts_with($bytes, '%PDF-')) {
                throw new ApiProblem('invalid_prescription_file', 'The prescription file content does not match an allowed JPEG, PNG, or PDF.', 422);
            }

            $this->pdfs->assertSupported($bytes);

            return ['mime' => 'application/pdf', 'extension' => 'pdf'];
        }

        if ($mime === 'image/jpeg' || str_starts_with($bytes, "\xFF\xD8")) {
            if ($mime !== 'image/jpeg') {
                throw new ApiProblem('invalid_prescription_file', 'The prescription file content does not match an allowed JPEG, PNG, or PDF.', 422);
            }

            $this->images->assertJpeg($bytes);

            return ['mime' => 'image/jpeg', 'extension' => 'jpg'];
        }

        if ($mime === 'image/png' || str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            if ($mime !== 'image/png') {
                throw new ApiProblem('invalid_prescription_file', 'The prescription file content does not match an allowed JPEG, PNG, or PDF.', 422);
            }

            $this->images->assertPng($bytes);

            return ['mime' => 'image/png', 'extension' => 'png'];
        }

        throw new ApiProblem('invalid_prescription_file', 'The prescription file is not a readable JPEG, PNG, or PDF.', 422);
    }

    public function displayName(?string $original): string
    {
        $base = basename(str_replace('\\', '/', (string) $original));
        $base = preg_replace('/[^A-Za-z0-9._ -]+/', '', $base) ?? '';
        $base = trim($base, ". \t");

        if ($base === '') {
            return 'prescription';
        }

        return mb_substr($base, 0, 80);
    }
}
