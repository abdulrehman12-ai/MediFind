<?php

declare(strict_types=1);

namespace App\Identity\Phone;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;

final class PakistaniMobileNormalizer
{
    public function normalize(string $input): string
    {
        $trimmed = trim($input);

        if ($trimmed == '') {
            throw new InvalidPhoneNumber('Enter a valid Pakistani mobile number.');
        }

        $util = PhoneNumberUtil::getInstance();

        try {
            $parsed = $util->parse($trimmed, 'PK');
        } catch (NumberParseException) {
            throw new InvalidPhoneNumber('Enter a valid Pakistani mobile number.');
        }

        if (! $util->isValidNumber($parsed) || $util->getRegionCodeForNumber($parsed) !== 'PK') {
            throw new InvalidPhoneNumber('Enter a valid Pakistani mobile number.');
        }

        $type = $util->getNumberType($parsed);

        if ($type !== PhoneNumberType::MOBILE && $type !== PhoneNumberType::FIXED_LINE_OR_MOBILE) {
            throw new InvalidPhoneNumber('Enter a valid Pakistani mobile number.');
        }

        return $util->format($parsed, PhoneNumberFormat::E164);
    }
}
