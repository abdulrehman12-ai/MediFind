<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class CorrectPrescriptionItemRequest extends ApiFormRequest
{
    /**
     * @return list<string>
     */
    protected function allowedFields(): array
    {
        return ['text', 'selected', 'selected_medicine_id'];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'text' => ['sometimes', 'nullable', 'string', 'max:180'],
            'selected' => ['required', 'boolean'],
            'selected_medicine_id' => ['nullable', 'uuid'],
        ];
    }
}
