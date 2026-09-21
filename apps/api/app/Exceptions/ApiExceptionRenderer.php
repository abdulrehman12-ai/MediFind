<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ApiExceptionRenderer
{
    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
        $message = $e instanceof HttpExceptionInterface && $e->getMessage() !== ''
            ? $e->getMessage()
            : 'An error occurred.';

        if ($status >= 500 && config('app.debug') !== true) {
            $message = 'An error occurred.';
        }

        return response()->json([
            'error' => [
                'code' => $status >= 500 ? 'server_error' : 'request_error',
                'message' => $message,
            ],
            'meta' => [
                'request_id' => $request->headers->get('X-Request-Id'),
            ],
        ], $status);
    }
}
