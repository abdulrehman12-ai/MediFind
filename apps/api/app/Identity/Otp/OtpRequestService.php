<?php

declare(strict_types=1);

namespace App\Identity\Otp;

use Illuminate\Support\Str;
use Throwable;

final class OtpRequestService
{
    public function __construct(
        private readonly OtpConfiguration $configuration,
        private readonly OtpHasher $hasher,
        private readonly OtpRedis $redis,
        private readonly SmsOtpProvider $provider,
    ) {}

    public function request(string $phoneE164, string $ip, string $requestId): OtpRequestOutcome
    {
        $code = $this->issueCode($requestId);
        $eventId = (string) Str::uuid();
        $challengeId = (string) Str::uuid();
        $phoneHmac = $this->hasher->phone($phoneE164);
        $otpHmac = $this->hasher->otp($challengeId, $phoneE164, $code);

        $result = $this->redis->request([
            $eventId,
            $challengeId,
            $phoneHmac,
            $this->hasher->ip($ip),
            $otpHmac,
            (string) config('medifind.otp.ip_attempt_limit'),
            (string) config('medifind.otp.phone_send_limit'),
            (string) config('medifind.otp.window_ms'),
            (string) config('medifind.otp.window_seconds'),
            (string) config('medifind.otp.ttl_seconds'),
            (string) config('medifind.otp.cooldown_seconds'),
        ]);

        $status = (string) ($result[0] ?? '');

        if ($status === 'ip_limited' || $status === 'phone_limited') {
            return new OtpRequestOutcome($status, null, (int) ($result[1] ?? 1), 0);
        }

        if ($status === 'cooldown') {
            return new OtpRequestOutcome('cooldown', null, (int) ($result[1] ?? 1), 0);
        }

        if ($status !== 'ok') {
            throw new OtpStoreUnavailable;
        }

        try {
            $this->provider->send($phoneE164, $challengeId, $code);
        } catch (Throwable) {
            try {
                $this->redis->discardUndelivered($challengeId, $phoneHmac);
            } catch (Throwable) {
                // A cleanup failure must not replace the delivery response or leak store details.
            }

            throw new OtpUnavailable('otp_delivery_unavailable', $requestId);
        }

        return new OtpRequestOutcome(
            'ok',
            $challengeId,
            (int) ($result[3] ?? config('medifind.otp.cooldown_seconds')),
            (int) ($result[2] ?? config('medifind.otp.ttl_seconds')),
        );
    }

    private function issueCode(string $requestId): string
    {
        if ($this->configuration->provider() === 'local') {
            $configured = (string) config('medifind.otp.local_code');

            if (! preg_match('/^\d{6}$/', $configured)) {
                throw new OtpUnavailable('otp_delivery_unavailable', $requestId);
            }

            return $configured;
        }

        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
