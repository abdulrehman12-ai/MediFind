<?php

declare(strict_types=1);

namespace App\Catalog;

use Normalizer;

final class CatalogText
{
    public static function normalize(?string $value): string
    {
        $normalized = Normalizer::normalize(trim((string) $value), Normalizer::FORM_KC);
        $folded = mb_strtolower($normalized === false ? trim((string) $value) : $normalized);
        $collapsed = preg_replace('/\s+/u', ' ', $folded);

        return trim((string) $collapsed);
    }
}
