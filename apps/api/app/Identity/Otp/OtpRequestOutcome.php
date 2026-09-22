<?php

declare(strict_types=1);

namespace App\Identity\Otp;

final class OtpRequestOutcome
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $challengeId,
        public readonly int $retryAfter,
        public readonly int $expiresIn,
    ) {}
}
