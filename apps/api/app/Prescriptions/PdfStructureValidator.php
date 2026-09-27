<?php

declare(strict_types=1);

namespace App\Prescriptions;

use App\Exceptions\ApiProblem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends the PDF to the isolated qpdf worker. This process does not parse it.
 */
final class PdfStructureValidator
{
    public function assertSupported(string $bytes): void
    {
        if (! str_starts_with($bytes, '%PDF-') || strlen($bytes) > PrescriptionFileInspector::MAX_BYTES) {
            $this->reject('The prescription file is not a readable PDF.');
        }

        $url = rtrim((string) config('medifind.prescriptions.pdf_worker_url'), '/');
        $token = (string) config('medifind.prescriptions.pdf_worker_token');

        if ($url === '' || $token === '') {
            throw new ApiProblem('storage_unavailable', 'The prescription file could not be checked.', 503);
        }

        try {
            $response = Http::timeout(10)
                ->connectTimeout(3)
                ->withHeaders([
                    'X-Pdf-Worker-Token' => $token,
                    'Accept' => 'application/json',
                ])
                ->withBody($bytes, 'application/pdf')
                ->post($url.'/check');
        } catch (ConnectionException) {
            throw new ApiProblem('storage_unavailable', 'The prescription file could not be checked.', 503);
        } catch (Throwable) {
            throw new ApiProblem('storage_unavailable', 'The prescription file could not be checked.', 503);
        }

        $code = '';
        $payload = $response->json();

        if (is_array($payload)) {
            $code = is_string($payload['code'] ?? null) ? $payload['code'] : '';
            if ($response->successful() && ($payload['ok'] ?? false) === true && (int) ($payload['pages'] ?? 0) >= 1) {
                return;
            }
        }

        if ($code === 'limit') {
            $this->reject('The prescription PDF exceeds the supported size, page, or processing limit.');
        }

        if ($code === 'encrypted') {
            $this->reject('The prescription PDF is encrypted.');
        }

        if ($code === 'malformed') {
            $this->reject('The prescription file is not a readable PDF.');
        }

        throw new ApiProblem('storage_unavailable', 'The prescription file could not be checked.', 503);
    }

    private function reject(string $message): never
    {
        throw new ApiProblem('invalid_prescription_file', $message, 422);
    }
}
