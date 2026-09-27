<?php

declare(strict_types=1);

namespace App\Prescriptions\Extraction;

use RuntimeException;

final class ExtractionTimeout extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Prescription extraction timed out.');
    }
}
