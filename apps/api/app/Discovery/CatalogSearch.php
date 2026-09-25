<?php

declare(strict_types=1);

namespace App\Discovery;

use Illuminate\Support\Facades\DB;

final class CatalogSearch
{
    /**
     * @return array{items: list<array<string, mixed>>, page: int, per_page: int, has_more: bool}
     */
    public function paginate(string $normalizedQuery, int $page, int $perPage): array
    {
        $prefix = SearchText::prefix($normalizedQuery);
        $contains = SearchText::contains($normalizedQuery);
        $offset = ($page - 1) * $perPage;

        $rows = DB::select(<<<'SQL'
SELECT
    medicines.id,
    medicines.generic_name,
    medicines.brand_name,
    medicines.strength,
    medicines.dosage_form,
    medicines.manufacturer,
    medicines.prescription_required,
    MIN(
        CASE
            WHEN medicines.normalized_generic_name = ?
                OR medicines.normalized_brand_name = ?
                OR aliases.normalized_alias = ?
                THEN 0
            WHEN medicines.normalized_generic_name LIKE ? ESCAPE '\'
                OR medicines.normalized_brand_name LIKE ? ESCAPE '\'
                OR aliases.normalized_alias LIKE ? ESCAPE '\'
                THEN 1
            ELSE 2
        END
    ) AS search_rank
FROM medicines
LEFT JOIN medicine_aliases AS aliases ON aliases.medicine_id = medicines.id
WHERE medicines.status = 'active'
AND medicines.controlled_drug IS FALSE
AND (
    medicines.normalized_generic_name LIKE ? ESCAPE '\'
    OR medicines.normalized_brand_name LIKE ? ESCAPE '\'
    OR aliases.normalized_alias LIKE ? ESCAPE '\'
)
GROUP BY
    medicines.id,
    medicines.generic_name,
    medicines.brand_name,
    medicines.strength,
    medicines.dosage_form,
    medicines.manufacturer,
    medicines.prescription_required
ORDER BY search_rank ASC, medicines.id ASC
LIMIT ? OFFSET ?
SQL, [
            $normalizedQuery,
            $normalizedQuery,
            $normalizedQuery,
            $prefix,
            $prefix,
            $prefix,
            $contains,
            $contains,
            $contains,
            $perPage + 1,
            $offset,
        ]);

        $hasMore = count($rows) > $perPage;
        $pageRows = array_slice($rows, 0, $perPage);
        $items = [];

        foreach ($pageRows as $row) {
            $items[] = [
                'id' => (string) $row->id,
                'generic_name' => (string) $row->generic_name,
                'brand_name' => $row->brand_name === null ? null : (string) $row->brand_name,
                'strength' => $row->strength === null ? null : (string) $row->strength,
                'dosage_form' => (string) $row->dosage_form,
                'manufacturer' => $row->manufacturer === null ? null : (string) $row->manufacturer,
                'prescription_required' => $this->boolean($row->prescription_required),
            ];
        }

        return [
            'items' => $items,
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => $hasMore,
        ];
    }

    private function boolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }
}
