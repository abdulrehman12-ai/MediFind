<?php

namespace Tests\Feature;

use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Tests\TestCase;

class ApiResponseMetaTest extends TestCase
{
    public function test_caller_metadata_cannot_replace_the_request_id(): void
    {
        $request = Request::create('/api/v1/medicines', 'GET');
        $request->headers->set('X-Request-Id', '11111111-1111-1111-1111-111111111111');

        $response = ApiResponse::data($request, [], 200, [
            'request_id' => 'spoofed',
            'page' => 1,
        ]);

        $meta = $response->getData(true)['meta'];

        $this->assertSame('11111111-1111-1111-1111-111111111111', $meta['request_id']);
        $this->assertSame(1, $meta['page']);
    }
}
