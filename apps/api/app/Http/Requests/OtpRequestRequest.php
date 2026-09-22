<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class OtpRequestRequest extends ApiFormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'phone_number' => ['required', 'string', 'max:32'],
        ];
    }

    protected function allowedFields(): array
    {
        return ['phone_number'];
    }
}
