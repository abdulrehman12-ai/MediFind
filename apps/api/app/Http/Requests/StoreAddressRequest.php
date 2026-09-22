<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class StoreAddressRequest extends ApiFormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:60'],
            'address_text' => ['required', 'string', 'max:300'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'place_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    protected function allowedFields(): array
    {
        return ['label', 'address_text', 'latitude', 'longitude', 'place_id', 'is_default'];
    }
}
