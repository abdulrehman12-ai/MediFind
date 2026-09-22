<?php

declare(strict_types=1);

namespace App\Identity\Otp;

use App\Exceptions\ApiProblem;
use App\Identity\CustomerAuthenticator;
use Throwable;

final class OtpVerificationService
{
    public function __construct(
        private readonly OtpHasher $hasher,
        private readonly OtpRedis $redis,
        private readonly CustomerAuthenticator $authenticator,
    ) {}

    /**
     * @return array{access_token: string, token_type: string, expires_at: string, customer: array<string, mixed>}
     */
    public function verify(string $phoneE164, string $challengeId, string $code, string $requestId): array
    {
        $token = bin2hex(random_bytes(16));
        $phoneHmac = $this->hasher->phone($phoneE164);

        if (! $this->redis->acquireLock($challengeId, $token)) {
            throw new ApiProblem('otp_in_progress', 'OTP verification is already in progress.', 429, [], 1);
        }

        try {
            $challenge = $this->redis->challenge($challengeId);

            if ($challenge === null) {
                throw new ApiProblem('otp_invalid', 'The code is invalid.', 422);
            }

            if (($challenge['phone_hmac'] ?? '') !== $phoneHmac) {
                throw new ApiProblem('otp_invalid', 'The code is invalid.', 422);
            }

            $expiresAt = (int) ($challenge['expires_at'] ?? 0);
            $now = $this->redisTime();

            if ($expiresAt === 0 || $now >= $expiresAt) {
                $this->redis->deleteChallenge($challengeId, $phoneHmac);

                throw new ApiProblem('otp_invalid', 'The code is invalid.', 422);
            }

            $candidate = $this->hasher->otp($challengeId, $phoneE164, $code);
            $stored = (string) ($challenge['otp_hmac'] ?? '');

            if (strlen($stored) !== strlen($candidate) || ! hash_equals($stored, $candidate)) {
                $this->redis->incrementFailure($challengeId, $phoneHmac);

                throw new ApiProblem('otp_invalid', 'The code is invalid.', 422);
            }

            $this->redis->consume($challengeId, $phoneHmac);
        } finally {
            $this->redis->releaseLock($challengeId, $token);
        }

        try {
            return $this->authenticator->establish($phoneE164);
        } catch (ApiProblem $problem) {
            throw $problem;
        } catch (Throwable) {
            throw new OtpUnavailable('otp_unavailable', $requestId);
        }
    }

    private function redisTime(): int
    {
        $time = $this->redis->time();

        return $time[0];
    }
}
