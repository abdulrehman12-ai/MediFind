<?php

declare(strict_types=1);

namespace App\Identity;

use Illuminate\Http\Request;

final class ClientIp
{
    public function from(Request $request): string
    {
        $header = $request->headers->get('X-Medifind-Client-IP');

        if (is_string($header) && $header !== '') {
            return $header;
        }

        $remote = $request->server('REMOTE_ADDR');

        return is_string($remote) && $remote !== '' ? $remote : '0.0.0.0';
    }
}
