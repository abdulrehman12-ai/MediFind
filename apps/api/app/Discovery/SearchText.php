<?php

declare(strict_types=1);

namespace App\Discovery;

final class SearchText
{
    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    public static function prefix(string $value): string
    {
        return self::escapeLike($value).'%';
    }

    public static function contains(string $value): string
    {
        return '%'.self::escapeLike($value).'%';
    }
}
