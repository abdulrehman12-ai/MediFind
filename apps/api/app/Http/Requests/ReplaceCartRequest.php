<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Validation\ValidationException;

final class ReplaceCartRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $items = $this->input('items');

        if (! is_array($items)) {
            return;
        }

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $unknown = array_diff(array_keys($item), ['medicine_id', 'quantity']);

            if ($unknown !== []) {
                throw ValidationException::withMessages([
                    'items.'.$index => ['This field is not allowed.'],
                ]);
            }
        }
    }

    /**
     * @return list<string>
     */
    protected function allowedFields(): array
    {
        return ['version', 'pharmacy_id', 'mode', 'prescription_id', 'customer_note', 'items'];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'version' => ['nullable', 'integer', 'min:1'],
            'pharmacy_id' => ['required', 'uuid'],
            'mode' => ['required', 'in:catalog_items,prescription_selected_items,prescription_full_image'],
            'prescription_id' => ['nullable', 'uuid'],
            'customer_note' => ['nullable', 'string', 'max:500'],
            'items' => ['present', 'array', 'max:10'],
            'items.*.medicine_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
        ];
    }
}
