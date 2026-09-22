<?php

namespace Tests\Unit;

use App\Exceptions\ApiProblem;
use App\Identity\DefaultAddressConflict;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;
use Tests\TestCase;

class DefaultAddressConflictTest extends TestCase
{
    public function test_second_one_default_violation_maps_to_address_conflict(): void
    {
        $exception = $this->violation('duplicate key value violates unique constraint "user_addresses_one_default_unique"');

        try {
            DefaultAddressConflict::rejectSecond($exception);
            $this->fail('The one-default violation was not mapped.');
        } catch (ApiProblem $problem) {
            $this->assertSame('address_conflict', $problem->errorCode);
            $this->assertSame(409, $problem->status);
        }
    }

    public function test_unrelated_unique_violations_are_not_address_conflicts(): void
    {
        $exception = $this->violation('duplicate key value violates unique constraint "users_email_active_unique"');

        $this->expectException(UniqueConstraintViolationException::class);
        DefaultAddressConflict::rejectSecond($exception);
    }

    private function violation(string $message): UniqueConstraintViolationException
    {
        return new UniqueConstraintViolationException('pgsql', 'insert', [], new RuntimeException($message));
    }
}
