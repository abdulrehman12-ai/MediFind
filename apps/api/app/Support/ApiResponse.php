<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use stdClass;

final class ApiResponse
{
    /**
     * @param  array<string, mixed>|list<array<string, mixed>>  $data
     * @param  array<string, mixed>  $meta
     */
    public static function data(Request $request, array $data, int $status = 200, array $meta = []): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => [
                ...$meta,
                'request_id' => $request->headers->get('X-Request-Id'),
            ],
        ], $status);
    }

    public static function noContent(): Response
    {
        return response()->noContent();
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function error(Request $request, string $code, string $message, int $status, array $details = [], ?int $retryAfter = null): JsonResponse
    {
        $response = response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details === [] ? new stdClass : $details,
            ],
            'meta' => [
                'request_id' => $request->headers->get('X-Request-Id'),
            ],
        ], $status);

        if ($retryAfter !== null) {
            $response->headers->set('Retry-After', (string) max(1, $retryAfter));
        }

        return $response;
    }
}
