<?php

declare(strict_types=1);

namespace App\Identity\Otp;

interface SmsOtpProvider
{
    public function send(string $phoneE164, string $challengeId, string $code): void;
}
