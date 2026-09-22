<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

final class UpdateProfileRequest extends ApiFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'nullable', 'string', 'min:1', 'max:120'],
            'email' => [
                'sometimes',
                'nullable',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->user()?->id)->whereNull('deleted_at'),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $normalized = [];

        if ($this->exists('full_name') && is_string($this->input('full_name'))) {
            $name = trim($this->input('full_name'));
            $normalized['full_name'] = $name === '' ? null : $name;
        }

        if ($this->exists('email') && is_string($this->input('email'))) {
            $email = strtolower(trim($this->input('email')));
            $normalized['email'] = $email === '' ? null : $email;
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    protected function allowedFields(): array
    {
        return ['full_name', 'email'];
    }
}
