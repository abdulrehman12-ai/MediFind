<?php

namespace App\Support;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

final class TestRedisCleanup
{
    public static function flushTestDatabase(): void
    {
        TestingSafety::assertSafeForTestRedisCleanup();

        $connection = Redis::connection();

        if (! $connection instanceof Connection) {
            throw new RuntimeException('Unexpected Redis connection type.');
        }

        $connection->flushdb();
    }
}
