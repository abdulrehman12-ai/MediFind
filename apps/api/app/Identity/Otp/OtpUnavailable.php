<?php

declare(strict_types=1);

namespace App\Identity\Otp;

use RuntimeException;

final class OtpUnavailable extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly string $requestId)
    {
        parent::__construct('OTP is temporarily unavailable.');
    }
}
