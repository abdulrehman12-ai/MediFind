<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Catalog\CatalogText;
use Illuminate\Validation\Validator;

final class SearchMedicinesRequest extends ApiFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxPerPage = (int) config('medifind.discovery.max_per_page');

        return [
            'q' => ['required', 'string'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.$maxPerPage],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $normalized = CatalogText::normalize((string) $this->input('q'));
            $length = mb_strlen($normalized);
            $minimum = (int) config('medifind.discovery.min_query_length');
            $maximum = (int) config('medifind.discovery.max_query_length');

            if ($length < $minimum) {
                $validator->errors()->add('q', 'Enter at least '.$minimum.' characters.');
            }

            if ($length > $maximum) {
                $validator->errors()->add('q', 'Enter at most '.$maximum.' characters.');
            }

            $page = (int) $this->input('page', 1);
            $perPage = (int) $this->input('per_page', config('medifind.discovery.default_per_page'));
            $maxOffset = (int) config('medifind.discovery.max_offset');

            if (($page - 1) * $perPage >= $maxOffset) {
                $validator->errors()->add('page', 'That page is outside the result window.');
            }
        });
    }

    public function normalizedQuery(): string
    {
        return CatalogText::normalize((string) $this->validated('q'));
    }

    public function pageNumber(): int
    {
        return (int) $this->integer('page', 1);
    }

    public function pageSize(): int
    {
        $value = $this->input('per_page');

        return $value === null
            ? (int) config('medifind.discovery.default_per_page')
            : (int) $value;
    }

    /**
     * @return list<string>
     */
    protected function allowedFields(): array
    {
        return ['q', 'page', 'per_page'];
    }
}
