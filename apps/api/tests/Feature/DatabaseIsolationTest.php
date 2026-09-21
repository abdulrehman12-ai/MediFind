<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseIsolationTest extends TestCase
{
    public function test_testing_context_uses_the_testing_database_and_role(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('medifind_testing', DB::selectOne('select current_database() as db')->db);
        $this->assertSame('medifind_test', DB::selectOne('select current_user as usr')->usr);
        $this->assertNotEmpty(DB::selectOne('select PostGIS_Version() as v')->v);
    }
}
