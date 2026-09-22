<?php

declare(strict_types=1);

namespace App\Identity\Otp;

use RuntimeException;

final class LocalConfiguredOtpProvider implements SmsOtpProvider
{
    public function send(string $phoneE164, string $challengeId, string $code): void
    {
        $configured = (string) config('medifind.otp.local_code');

        if (! preg_match('/^\d{6}$/', $configured) || ! hash_equals($configured, $code)) {
            throw new RuntimeException('OTP delivery failed.');
        }
    }
}
