<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('nginx-ip')]
class NginxClientIpTest extends TestCase
{
    public function test_nginx_does_not_reflect_a_spoofed_client_ip(): void
    {
        $spoof = '203.0.113.50';
        $response = Http::timeout(10)
            ->withHeaders([
                'X-Medifind-Client-IP' => $spoof,
                'Accept' => 'application/json',
            ])
            ->get('http://nginx/api/v1/health/live');

        $response->throw();

        foreach ($response->headers() as $name => $values) {
            $this->assertNotSame('x-medifind-client-ip', strtolower((string) $name));
        }

        $this->assertStringNotContainsString($spoof, $response->body());
        $this->assertStringNotContainsString('X-Medifind-Client-IP', $response->body());
        $this->assertSame('live', $response->json('status'));
    }
}
