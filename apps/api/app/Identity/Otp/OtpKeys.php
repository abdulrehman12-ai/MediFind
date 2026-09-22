<?php

declare(strict_types=1);

namespace App\Identity\Otp;

final class OtpKeys
{
    public const PREFIX = 'mf:otp:v1:';

    public static function attempt(string $ipHmac): string
    {
        return self::PREFIX.'attempt:'.$ipHmac;
    }

    public static function send(string $phoneHmac): string
    {
        return self::PREFIX.'send:'.$phoneHmac;
    }

    public static function cooldown(string $phoneHmac): string
    {
        return self::PREFIX.'cool:'.$phoneHmac;
    }

    public static function active(string $phoneHmac): string
    {
        return self::PREFIX.'active:'.$phoneHmac;
    }

    public static function challenge(string $challengeId): string
    {
        return self::PREFIX.'ch:'.$challengeId;
    }

    public static function lock(string $challengeId): string
    {
        return self::PREFIX.'lock:'.$challengeId;
    }

    public static function publicBurst(string $ipHmac): string
    {
        return self::PREFIX.'public:'.$ipHmac;
    }
}
