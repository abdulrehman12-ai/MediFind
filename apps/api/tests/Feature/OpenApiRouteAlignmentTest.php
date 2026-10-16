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

        $this->assertStringContainsString('Phase 6', (string) ($document['info']['description'] ?? ''));
        $this->assertArrayHasKey('PickupWindow', $schemas);
        $this->assertSame(
            ['starts_at', 'ends_at', 'collect_until', 'grace_seconds'],
            $schemas['PickupWindow']['required'] ?? null,
        );
        $this->assertSame(
            '#/components/schemas/PickupWindow',
            $schemas['Order']['properties']['pickup_window']['allOf'][0]['$ref'] ?? null,
        );
        $this->assertContains('unfulfillable', $schemas['Order']['properties']['status']['enum'] ?? []);
        $this->assertContains('not_collected', $schemas['Order']['properties']['status']['enum'] ?? []);
        $this->assertContains('not_collected', $schemas['OrderEvent']['properties']['event']['enum'] ?? []);
        $this->assertNotContains('unfulfillable', $schemas['OrderEvent']['properties']['event']['enum'] ?? []);
        $this->assertSame('date-time', $schemas['OrderEvent']['properties']['at']['format'] ?? null);
        $this->assertSame('date-time', $schemas['Customer']['properties']['phone_verified_at']['format'] ?? null);
        $this->assertStringContainsString(
            'ignore unknown',
            (string) ($schemas['OrderEvent']['properties']['event']['description'] ?? ''),
        );
    }
}
