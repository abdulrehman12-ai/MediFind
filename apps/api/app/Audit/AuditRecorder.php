<?php

declare(strict_types=1);

namespace App\Audit;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

class AuditRecorder
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        ?User $actor,
        string $action,
        string $subjectType,
        string $subjectId,
        ?string $pharmacyId,
        array $metadata = [],
    ): void {
        AuditEvent::query()->create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'pharmacy_id' => $pharmacyId,
            'metadata' => $this->redact($metadata),
            'request_id' => $this->requestId(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function redact(array $metadata): array
    {
        $clean = [];

        foreach ($metadata as $key => $value) {
            $name = strtolower((string) $key);

            if (str_contains($name, 'password')
                || str_contains($name, 'token')
                || str_contains($name, 'secret')
                || str_contains($name, 'recovery')
                || str_contains($name, 'phone')
                || str_contains($name, 'otp')
                || str_contains($name, 'email')
                || str_contains($name, 'mfa')
                || str_contains($name, 'totp')
                || str_contains($name, 'authenticator')
                || str_contains($name, 'address')
                || str_contains($name, 'customer')
                || str_contains($name, 'cipher')) {
                continue;
            }

            $clean[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $clean;
    }

    private function requestId(): ?string
    {
        $requestId = Request::header('X-Request-Id');

        return is_string($requestId) && Str::isUuid($requestId) ? $requestId : null;
    }
}
