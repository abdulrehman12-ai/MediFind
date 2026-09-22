<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_live_does_not_query_dependencies_and_returns_ok(): void
    {
        $response = $this->getJson('/api/v1/health/live');

        $response->assertOk()
            ->assertJsonPath('status', 'live')
            ->assertHeader('X-Request-Id');

        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
        $this->assertArrayNotHasKey('checks', $response->json());
    }

    public function test_ready_succeeds_against_testing_database_and_test_redis(): void
    {
        $response = $this->getJson('/api/v1/health/ready');

        $response->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('checks.database', 'ok')
            ->assertJsonPath('checks.cache', 'ok');
    }

    public function test_spoofed_request_id_is_replaced(): void
    {
        $response = $this->getJson('/api/v1/health/live', [
            'X-Request-Id' => 'spoofed-client-id',
        ]);

        $response->assertOk();
        $requestId = (string) $response->headers->get('X-Request-Id');
        $this->assertNotSame('spoofed-client-id', $requestId);
        $this->assertSame($requestId, $response->json('meta.request_id'));
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $requestId);
    }

    public function test_up_route_is_not_registered(): void
    {
        $this->get('/up')->assertNotFound();
    }

    public function test_preflight_allows_the_authorization_header(): void
    {
        $response = $this->call('OPTIONS', '/api/v1/me', server: [
            'HTTP_ORIGIN' => 'http://127.0.0.1:8080',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);

        $response->assertNoContent();
        $allowedHeaders = strtolower((string) $response->headers->get('Access-Control-Allow-Headers'));
        $this->assertStringContainsString('authorization', $allowedHeaders);
        $this->assertStringNotContainsString('*', $allowedHeaders);
        $this->assertSame('http://127.0.0.1:8080', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
    }
}
