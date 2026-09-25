<?php

declare(strict_types=1);

namespace App\Pharmacy;

final class Coordinates
{
    public static function assertPair(?string $latitude, ?string $longitude): void
    {
        if (($latitude === null) !== ($longitude === null)) {
            throw new PharmacyRuleException('Latitude and longitude must be provided together.');
        }

        if ($latitude === null || $longitude === null) {
            return;
        }

        self::assert($latitude, -90, 90, 'Latitude');
        self::assert($longitude, -180, 180, 'Longitude');
    }

    private static function assert(string $value, float $min, float $max, string $label): void
    {
        if (! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            throw new PharmacyRuleException($label.' is not a valid coordinate.');
        }

        $number = (float) $value;

        if (! is_finite($number) || $number < $min || $number > $max) {
            throw new PharmacyRuleException($label.' is outside the valid range.');
        }
    }
}
