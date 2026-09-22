<?php

namespace Tests\Unit;

use Tests\TestCase;

class SanctumConfigurationTest extends TestCase
{
    public function test_stateful_domains_are_empty(): void
    {
        $this->assertSame([], config('sanctum.stateful'));
        $this->assertSame(43200, config('sanctum.expiration'));
    }
}
