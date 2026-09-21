<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

final class TestingSafety
{
    public static function assertSafeForDestructiveDatabaseWork(): void
    {
        self::assertTestingEnvironment();
        self::assertConfiguredTestingDatabase();
        self::assertLiveTestingDatabase();
    }

    public static function assertSafeForTestRedisCleanup(): void
    {
        self::assertTestingEnvironment();

        $host = (string) config('database.redis.default.host');

        if ($host !== 'redis_test') {
            throw new RuntimeException('Redis cleanup is only allowed against redis_test.');
        }

        try {
            $client = Redis::connection()->client();
            if (is_object($client) && method_exists($client, 'getHost')) {
                $connectedHost = (string) $client->getHost();
                if ($connectedHost !== '' && $connectedHost !== 'redis_test') {
                    throw new RuntimeException('Connected Redis host is not redis_test.');
                }
            }
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RuntimeException('Redis connection is unavailable; cleanup aborted.', 0, $e);
        }
    }

    public static function assertTestingEnvironment(): void
    {
        if (app()->environment() !== 'testing') {
            throw new RuntimeException('APP_ENV must be testing.');
        }
    }

    private static function assertConfiguredTestingDatabase(): void
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        $username = (string) config('database.connections.'.config('database.default').'.username');

        if ($database !== 'medifind_testing') {
            throw new RuntimeException('Destructive database work is only allowed against medifind_testing.');
        }

        if ($username !== 'medifind_test') {
            throw new RuntimeException('Destructive database work is only allowed as medifind_test.');
        }
    }

    private static function assertLiveTestingDatabase(): void
    {
        try {
            $row = DB::selectOne('select current_database() as db, current_user as usr');
        } catch (Throwable $e) {
            throw new RuntimeException('Database connection is unavailable; destructive work aborted.', 0, $e);
        }

        if ($row === null) {
            throw new RuntimeException('Database connection is unavailable; destructive work aborted.');
        }

        $database = (string) ($row->db ?? '');
        $username = (string) ($row->usr ?? '');

        if ($database !== 'medifind_testing') {
            throw new RuntimeException('Live database is not medifind_testing.');
        }

        if ($username !== 'medifind_test') {
            throw new RuntimeException('Live database role is not medifind_test.');
        }
    }
}
