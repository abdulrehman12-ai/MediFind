<?php

declare(strict_types=1);

namespace App\Identity\Otp;

use RuntimeException;

final class OtpStoreUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('OTP is temporarily unavailable.');
    }
}
