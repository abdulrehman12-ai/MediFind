<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class SharePrescriptionRequest extends ApiFormRequest
{
    /**
     * @return list<string>
     */
    protected function allowedFields(): array
    {
        return ['mode', 'pharmacy_id'];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', 'in:selected_items,full_image'],
            'pharmacy_id' => ['required', 'uuid'],
        ];
    }
}
