<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    public function live(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'live',
            'meta' => [
                'request_id' => $request->headers->get('X-Request-Id'),
            ],
        ]);
    }

    public function ready(Request $request): JsonResponse
    {
        $database = $this->databaseReady();
        $cache = $this->cacheReady();
        $ready = $database && $cache;

        return response()->json([
            'status' => $ready ? 'ready' : 'not_ready',
            'checks' => [
                'database' => $database ? 'ok' : 'failed',
                'cache' => $cache ? 'ok' : 'failed',
            ],
            'meta' => [
                'request_id' => $request->headers->get('X-Request-Id'),
            ],
        ], $ready ? 200 : 503);
    }

    private function databaseReady(): bool
    {
        try {
            DB::select('select 1');
            DB::select('select PostGIS_Version()');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function cacheReady(): bool
    {
        try {
            Redis::connection()->ping();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
