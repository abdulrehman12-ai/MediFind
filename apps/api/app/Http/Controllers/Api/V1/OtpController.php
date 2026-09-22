<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiProblem;
use App\Http\Controllers\Controller;
use App\Http\Requests\OtpRequestRequest;
use App\Http\Requests\OtpVerifyRequest;
use App\Identity\ClientIp;
use App\Identity\Otp\OtpRequestService;
use App\Identity\Otp\OtpUnavailable;
use App\Identity\Otp\OtpVerificationService;
use App\Identity\Phone\InvalidPhoneNumber;
use App\Identity\Phone\PakistaniMobileNormalizer;
use App\Identity\SecurityEvent;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class OtpController extends Controller
{
    public function request(
        OtpRequestRequest $request,
        PakistaniMobileNormalizer $normalizer,
        ClientIp $clientIp,
        OtpRequestService $service,
    ): JsonResponse {
        $requestId = (string) $request->headers->get('X-Request-Id');

        try {
            $phone = $normalizer->normalize($request->string('phone_number')->toString());
        } catch (InvalidPhoneNumber) {
            throw ValidationException::withMessages([
                'phone_number' => ['Enter a valid Pakistani mobile number.'],
            ]);
        }

        try {
            $outcome = $service->request($phone, $clientIp->from($request), $requestId);
        } catch (OtpUnavailable $unavailable) {
            Log::info($unavailable->errorCode, ['request_id' => $requestId]);

            return ApiResponse::error($request, $unavailable->errorCode, 'OTP delivery is temporarily unavailable.', 503);
        }

        if ($outcome->status !== 'ok' || $outcome->challengeId === null) {
            SecurityEvent::warning('otp_throttled', $requestId);
            $code = $outcome->status === 'cooldown' ? 'otp_cooldown' : 'otp_rate_limited';

            return ApiResponse::error($request, $code, 'Too many OTP requests.', 429, [], $outcome->retryAfter);
        }

        SecurityEvent::info('otp_requested', $requestId);

        return ApiResponse::data($request, [
            'challenge_id' => $outcome->challengeId,
            'expires_in' => $outcome->expiresIn,
            'retry_after' => $outcome->retryAfter,
        ], 202);
    }

    public function verify(
        OtpVerifyRequest $request,
        PakistaniMobileNormalizer $normalizer,
        OtpVerificationService $service,
    ): JsonResponse {
        $requestId = (string) $request->headers->get('X-Request-Id');

        try {
            $phone = $normalizer->normalize($request->string('phone_number')->toString());
        } catch (InvalidPhoneNumber) {
            throw ValidationException::withMessages([
                'phone_number' => ['Enter a valid Pakistani mobile number.'],
            ]);
        }

        try {
            $session = $service->verify(
                $phone,
                $request->string('challenge_id')->toString(),
                $request->string('otp')->toString(),
                $requestId,
            );
        } catch (OtpUnavailable $unavailable) {
            Log::info($unavailable->errorCode, ['request_id' => $requestId]);

            return ApiResponse::error($request, 'otp_unavailable', 'OTP verification is temporarily unavailable.', 503);
        } catch (ApiProblem $problem) {
            if ($problem->errorCode === 'otp_invalid') {
                SecurityEvent::warning('otp_verify_failed', $requestId);
            } elseif ($problem->errorCode === 'otp_in_progress') {
                SecurityEvent::warning('otp_throttled', $requestId);
            } elseif (in_array($problem->errorCode, ['account_role_forbidden', 'account_inactive'], true)) {
                SecurityEvent::warning('authorization_denied', $requestId);
            }

            throw $problem;
        }

        SecurityEvent::info('otp_verify_succeeded', $requestId);

        return ApiResponse::data($request, $session);
    }
}
