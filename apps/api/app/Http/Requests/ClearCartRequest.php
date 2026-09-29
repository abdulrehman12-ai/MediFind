<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class ClearCartRequest extends ApiFormRequest
{
    /**
     * @return list<string>
     */
    protected function allowedFields(): array
    {
        return ['version'];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
        ];
    }
}
