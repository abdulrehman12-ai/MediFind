<?php

declare(strict_types=1);

namespace App\Identity\Otp;

final class OtpHasher
{
    public function __construct(private readonly OtpConfiguration $configuration) {}

    public function phone(string $e164): string
    {
        return $this->hmac('phone|'.$e164);
    }

    public function ip(string $ip): string
    {
        return $this->hmac('ip|'.$ip);
    }

    public function otp(string $challengeId, string $e164, string $code): string
    {
        return $this->hmac('otp|'.$challengeId.'|'.$e164.'|'.$code);
    }

    private function hmac(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->configuration->hmacKey());
    }
}
