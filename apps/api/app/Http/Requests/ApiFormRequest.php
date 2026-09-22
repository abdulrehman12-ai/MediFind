<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

abstract class ApiFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $unknown = array_diff(array_keys($this->all()), $this->allowedFields());

        if ($unknown === []) {
            return;
        }

        $messages = [];

        foreach ($unknown as $field) {
            $messages[$field] = ['This field is not allowed.'];
        }

        throw ValidationException::withMessages($messages);
    }

    /**
     * @return list<string>
     */
    abstract protected function allowedFields(): array;
}
