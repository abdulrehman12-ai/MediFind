<?php

namespace Tests\Unit;

use App\Support\TestingSafety;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class TestingSafetyTest extends TestCase
{
    public function test_testing_safety_passes_in_the_phpunit_environment(): void
    {
        TestingSafety::assertSafeForDestructiveDatabaseWork();
        TestingSafety::assertSafeForTestRedisCleanup();
        $this->assertTrue(true);
    }

    public function test_testing_safety_rejects_non_testing_environment(): void
    {
        app()['env'] = 'local';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_ENV must be testing.');
        TestingSafety::assertSafeForDestructiveDatabaseWork();
    }

    public function test_testing_safety_rejects_wrong_configured_database(): void
    {
        config(['database.connections.pgsql.database' => 'medifind']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Destructive database work is only allowed against medifind_testing.');
        TestingSafety::assertSafeForDestructiveDatabaseWork();
    }

    public function test_testing_safety_rejects_wrong_configured_username(): void
    {
        config(['database.connections.pgsql.username' => 'medifind_dev']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Destructive database work is only allowed as medifind_test.');
        TestingSafety::assertSafeForDestructiveDatabaseWork();
    }

    public function test_testing_safety_rejects_live_database_mismatch(): void
    {
        DB::shouldReceive('selectOne')->once()->andReturn((object) [
            'db' => 'medifind',
            'usr' => 'medifind_test',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Live database is not medifind_testing.');
        TestingSafety::assertSafeForDestructiveDatabaseWork();
    }

    public function test_testing_safety_rejects_live_role_mismatch(): void
    {
        DB::shouldReceive('selectOne')->once()->andReturn((object) [
            'db' => 'medifind_testing',
            'usr' => 'medifind_dev',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Live database role is not medifind_test.');
        TestingSafety::assertSafeForDestructiveDatabaseWork();
    }

    public function test_testing_safety_rejects_unavailable_database_connection(): void
    {
        DB::shouldReceive('selectOne')->once()->andThrow(new RuntimeException('connection refused'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Database connection is unavailable; destructive work aborted.');
        TestingSafety::assertSafeForDestructiveDatabaseWork();
    }
}
