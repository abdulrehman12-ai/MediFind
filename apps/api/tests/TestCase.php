<?php

namespace Tests;

use App\Support\TestingSafety;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TestingSafety::assertSafeForDestructiveDatabaseWork();
        TestingSafety::assertSafeForTestRedisCleanup();
    }

    protected function beforeRefreshingDatabase()
    {
        TestingSafety::assertSafeForDestructiveDatabaseWork();
    }
}
