<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class OpenApiRouteAlignmentTest extends TestCase
{
    public function test_openapi_document_matches_phase_1b_routes(): void
    {
        $path = is_file('/var/www/docs/api/openapi-v1.yaml')
            ? '/var/www/docs/api/openapi-v1.yaml'
            : dirname(base_path(), 2).'/docs/api/openapi-v1.yaml';

        $document = Yaml::parseFile($path);
        $this->assertIsArray($document);
        $this->assertStringStartsWith('3.', (string) ($document['openapi'] ?? ''));
        $this->assertArrayHasKey('info', $document);
        $this->assertArrayHasKey('paths', $document);
        $this->assertIsArray($document['paths']);

        $operationIds = [];
        $spec = [];

        foreach ($document['paths'] as $path => $operations) {
            $this->assertIsArray($operations);
            foreach ($operations as $method => $operation) {
                if (in_array($method, ['parameters', 'summary', 'description'], true)) {
                    continue;
                }
                $this->assertIsArray($operation);
                $operationId = (string) ($operation['operationId'] ?? '');
                $this->assertNotSame('', $operationId);
                $this->assertNotContains($operationId, $operationIds);
                $operationIds[] = $operationId;
                $spec[strtoupper((string) $method).' '.$path] = true;
            }
        }

        $routes = [];

        foreach (Route::getRoutes() as $route) {
            $uri = '/'.ltrim($route->uri(), '/');
            if (! str_starts_with($uri, '/api/v1/')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $routes[$method.' '.$uri] = true;
            }
        }

        $this->assertEqualsCanonicalizing(array_keys($routes), array_keys($spec));

        $schemas = $document['components']['schemas'] ?? null;
        $this->assertIsArray($schemas);
        $customer = $schemas['Customer']['properties'] ?? null;
        $this->assertIsArray($customer);
        $this->assertArrayHasKey('phone_masked', $customer);
        $this->assertArrayNotHasKey('phone_number', $customer);
        $this->assertArrayNotHasKey('phone_e164', $customer);
        $this->assertSame(
            ['challenge_id', 'expires_in', 'retry_after'],
            $schemas['OtpRequestData']['required'] ?? null,
        );
        $this->assertContains('phone_masked', $schemas['Customer']['required'] ?? []);
        $this->assertArrayHasKey('ErrorEnvelope', $schemas);
        $this->assertArrayHasKey('OtpVerificationSuccess', $schemas);
        $this->assertArrayHasKey('Address', $schemas);
        $this->assertArrayHasKey('AddressList', $schemas);
        $this->assertArrayHasKey('bearerAuth', $document['components']['securitySchemes'] ?? []);
    }
}
