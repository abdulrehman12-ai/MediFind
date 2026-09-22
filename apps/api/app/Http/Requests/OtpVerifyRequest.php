<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class OtpVerifyRequest extends ApiFormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'phone_number' => ['required', 'string', 'max:32'],
            'challenge_id' => ['required', 'uuid'],
            'otp' => ['required', 'digits:6'],
        ];
    }

    protected function allowedFields(): array
    {
        return ['phone_number', 'challenge_id', 'otp'];
    }
}
