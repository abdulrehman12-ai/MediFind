<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class UpdateAddressRequest extends ApiFormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'string', 'max:60'],
            'address_text' => ['sometimes', 'string', 'max:300'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'place_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_default' => ['sometimes', 'accepted'],
        ];
    }

    protected function allowedFields(): array
    {
        return ['label', 'address_text', 'latitude', 'longitude', 'place_id', 'is_default'];
    }
}
