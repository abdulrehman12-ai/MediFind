<?php

namespace App\Exceptions;

use App\Identity\Otp\OtpStoreUnavailable;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class ApiExceptionRenderer
{
    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        if ($e instanceof OtpStoreUnavailable) {
            return ApiResponse::error($request, 'otp_unavailable', 'OTP is temporarily unavailable.', 503);
        }

        if ($e instanceof ApiProblem) {
            return ApiResponse::error($request, $e->errorCode, $e->getMessage(), $e->status, $e->details, $e->retryAfter);
        }

        if ($e instanceof ValidationException) {
            return ApiResponse::error($request, 'validation_failed', 'The given data was invalid.', 422, $e->errors());
        }

        if ($e instanceof AuthenticationException) {
            return ApiResponse::error($request, 'unauthenticated', 'Unauthenticated.', 401);
        }

        if ($e instanceof AuthorizationException) {
            return ApiResponse::error($request, 'forbidden', 'This action is unauthorized.', 403);
        }

        if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
            return ApiResponse::error($request, 'not_found', 'The requested resource was not found.', 404);
        }

        $status = 500;
        $message = 'An error occurred.';

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            if ($status < 500 && $e->getMessage() !== '') {
                $message = $e->getMessage();
            }
        }

        return ApiResponse::error(
            $request,
            $status >= 500 ? 'server_error' : 'request_error',
            $message,
            $status,
        );
    }
}
