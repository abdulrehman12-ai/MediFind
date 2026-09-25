<?php

declare(strict_types=1);

namespace App\Enums;

enum CredentialStatus: string
{
    case Unverified = 'unverified';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Expired = 'expired';
}
