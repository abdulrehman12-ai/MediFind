<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class CancelOrderRequest extends ApiFormRequest
{
    /**
     * @return list<string>
     */
    protected function allowedFields(): array
    {
        return ['reason'];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:200'],
        ];
    }
}
