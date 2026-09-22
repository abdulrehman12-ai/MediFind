<?php

declare(strict_types=1);

namespace App\Identity\Phone;

final class MaskedPhone
{
    public static function fromE164(string $e164): string
    {
        if (preg_match('/^\+92\d{10}$/', $e164) !== 1) {
            return '+92*********';
        }

        return substr($e164, 0, 6).'***'.substr($e164, -4);
    }
}
