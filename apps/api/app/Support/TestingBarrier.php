<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Test-only pause used to overlap two transactions at a lock boundary.
 * Production config leaves every barrier unset.
 */
final class TestingBarrier
{
    public static function wait(string $name): void
    {
        $hook = config('medifind.testing.barriers.'.$name);

        if (is_callable($hook)) {
            $hook();
        }
    }
}
