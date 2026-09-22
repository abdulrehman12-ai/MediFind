<?php

declare(strict_types=1);

namespace App\Identity;

use App\Exceptions\ApiProblem;
use Illuminate\Database\UniqueConstraintViolationException;

final class DefaultAddressConflict
{
    public static function isOneDefault(UniqueConstraintViolationException $exception): bool
    {
        return str_contains($exception->getMessage(), 'user_addresses_one_default_unique');
    }

    public static function rejectSecond(UniqueConstraintViolationException $exception): never
    {
        if (self::isOneDefault($exception)) {
            throw new ApiProblem('address_conflict', 'The default address could not be saved.', 409);
        }

        throw $exception;
    }
}
