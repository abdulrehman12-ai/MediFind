<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Validation\ValidationException;

final class SubmitOrderRequest extends ApiFormRequest
{
    /**
     * @return list<string>
     */
    protected function allowedFields(): array
    {
        return ['cart_version'];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cart_version' => ['required', 'integer', 'min:1'],
        ];
    }

    public function idempotencyKey(): string
    {
        $key = $this->header('Idempotency-Key');

        if (! is_string($key) || preg_match('/^[A-Za-z0-9_-]{16,128}$/', $key) !== 1) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => ['Provide an idempotency key of 16 to 128 letters, numbers, underscores, or hyphens.'],
            ]);
        }

        return $key;
    }
}
