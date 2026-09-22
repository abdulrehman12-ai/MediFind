<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class ApiProblem extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
        public readonly array $details = [],
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }
}
