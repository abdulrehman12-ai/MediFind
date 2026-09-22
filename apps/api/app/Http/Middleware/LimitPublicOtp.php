<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiProblem;
use App\Identity\ClientIp;
use App\Identity\Otp\OtpHasher;
use App\Identity\Otp\OtpRedis;
use App\Identity\SecurityEvent;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class LimitPublicOtp
{
    public function __construct(
        private readonly ClientIp $clientIp,
        private readonly OtpHasher $hasher,
        private readonly OtpRedis $redis,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) $request->headers->get('X-Request-Id');
        $decision = $this->redis->consumePublicAllowance(
            $this->hasher->ip($this->clientIp->from($request)),
            (int) config('medifind.otp.public_ip_limit'),
            (int) config('medifind.otp.public_window_seconds'),
        );

        if (! $decision['allowed']) {
            SecurityEvent::warning('otp_throttled', $requestId);

            throw new ApiProblem('otp_rate_limited', 'Too many OTP requests.', 429, [], $decision['retry_after']);
        }

        return $next($request);
    }
}
