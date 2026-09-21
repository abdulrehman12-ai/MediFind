<?php

namespace Tests\Feature;

use App\Support\TestRedisCleanup;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class RedisIsolationTest extends TestCase
{
    public function test_tests_use_redis_test_not_development_redis(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('redis_test', (string) config('database.redis.default.host'));

        Redis::set('medifind:isolation:test', 'test-only');
        $this->assertSame('test-only', Redis::get('medifind:isolation:test'));
    }

    public function test_cleanup_guard_allows_flushdb_only_on_test_redis(): void
    {
        Redis::set('medifind:cleanup', '1');
        TestRedisCleanup::flushTestDatabase();
        $this->assertNull(Redis::get('medifind:cleanup'));
    }
}
