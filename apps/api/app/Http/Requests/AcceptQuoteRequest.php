<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class AcceptQuoteRequest extends ApiFormRequest
{
    /**
     * @return list<string>
     */
    protected function allowedFields(): array
    {
        return ['quote_id', 'version'];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'quote_id' => ['required', 'uuid'],
            'version' => ['required', 'integer', 'min:1'],
        ];
    }
}
