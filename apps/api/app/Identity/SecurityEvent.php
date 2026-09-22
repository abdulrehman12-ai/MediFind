<?php

declare(strict_types=1);

namespace App\Identity;

use Illuminate\Support\Facades\Log;

final class SecurityEvent
{
    public static function info(string $event, string $requestId): void
    {
        Log::info($event, ['request_id' => $requestId]);
    }

    public static function warning(string $event, string $requestId): void
    {
        Log::warning($event, ['request_id' => $requestId]);
    }
}
