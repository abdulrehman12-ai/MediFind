<?php

namespace App\Support;

use Illuminate\Support\Facades\Redis;

final class TestRedisCleanup
{
    public static function flushTestDatabase(): void
    {
        TestingSafety::assertSafeForTestRedisCleanup();

        Redis::connection()->flushdb();
    }
}
