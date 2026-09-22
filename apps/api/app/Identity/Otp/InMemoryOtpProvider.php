<?php

declare(strict_types=1);

namespace App\Identity\Otp;

final class InMemoryOtpProvider implements SmsOtpProvider
{
    /** @var array<string, string> */
    private array $codes = [];

    public function send(string $phoneE164, string $challengeId, string $code): void
    {
        $this->codes[$challengeId] = $code;
    }

    public function codeFor(string $challengeId): ?string
    {
        return $this->codes[$challengeId] ?? null;
    }
}
