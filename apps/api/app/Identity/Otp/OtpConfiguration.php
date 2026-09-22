<?php

declare(strict_types=1);

namespace App\Identity\Otp;

use RuntimeException;

final class OtpConfiguration
{
    public function assertRuntimeSafe(): void
    {
        if ($this->runningPackageDiscover()) {
            return;
        }

        $this->assertKey();

        if (! app()->environment('production')) {
            $this->assertKnownLocalProvider();

            return;
        }

        $provider = $this->provider();

        if ($provider === 'local' || $provider === 'memory') {
            throw new RuntimeException('OTP provider is not allowed in production.');
        }

        if (filled(config('medifind.otp.local_code'))) {
            throw new RuntimeException('LOCAL_OTP_CODE is not allowed in production.');
        }

        throw new RuntimeException('OTP provider is not configured for production.');
    }

    public function provider(): string
    {
        return (string) config('medifind.otp.provider');
    }

    public function hmacKey(): string
    {
        $decoded = $this->decodeKey((string) config('medifind.otp.hmac_key'));

        if ($decoded === null) {
            throw new RuntimeException('OTP_HMAC_KEY is missing or invalid.');
        }

        return $decoded;
    }

    private function assertKnownLocalProvider(): void
    {
        $provider = $this->provider();

        if ($provider === 'memory') {
            if (! app()->environment('testing')) {
                throw new RuntimeException('OTP memory provider is only available in testing.');
            }

            return;
        }

        if ($provider !== 'local') {
            throw new RuntimeException('OTP provider is not configured.');
        }

        $code = (string) config('medifind.otp.local_code');

        if (preg_match('/^\d{6}$/', $code) !== 1) {
            throw new RuntimeException('LOCAL_OTP_CODE is missing or invalid.');
        }
    }

    private function assertKey(): void
    {
        $raw = (string) config('medifind.otp.hmac_key');
        $decoded = $this->decodeKey($raw);

        if ($decoded === null) {
            throw new RuntimeException('OTP_HMAC_KEY is missing or invalid.');
        }

        $appKey = (string) config('app.key');

        if ($appKey !== '' && hash_equals($appKey, $raw)) {
            throw new RuntimeException('OTP_HMAC_KEY is missing or invalid.');
        }

        if (str_starts_with($appKey, 'base64:')) {
            $appKeyBytes = base64_decode(substr($appKey, 7), true);

            if (is_string($appKeyBytes) && $appKeyBytes !== '' && hash_equals($appKeyBytes, $decoded)) {
                throw new RuntimeException('OTP_HMAC_KEY is missing or invalid.');
            }
        }
    }

    private function decodeKey(string $raw): ?string
    {
        if (str_starts_with($raw, 'base64:')) {
            $decoded = base64_decode(substr($raw, 7), true);

            if (! is_string($decoded) || strlen($decoded) < 32) {
                return null;
            }

            return $decoded;
        }

        if (strlen($raw) < 64 || ! ctype_xdigit($raw) || strlen($raw) % 2 !== 0) {
            return null;
        }

        $decoded = hex2bin($raw);

        if (! is_string($decoded) || strlen($decoded) < 32) {
            return null;
        }

        return $decoded;
    }

    private function runningPackageDiscover(): bool
    {
        if (! app()->runningInConsole()) {
            return false;
        }

        $argv = $_SERVER['argv'] ?? [];

        if ($argv === []) {
            return false;
        }

        foreach (array_slice($argv, 1) as $token) {
            if (! is_string($token) || $token === '' || str_starts_with($token, '-')) {
                continue;
            }

            return $token === 'package:discover';
        }

        return false;
    }
}
