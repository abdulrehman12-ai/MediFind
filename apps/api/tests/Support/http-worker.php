<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$payload = json_decode((string) file_get_contents($argv[1]), true);

if (! is_array($payload)) {
    file_put_contents($argv[2], json_encode(['status' => 0, 'code' => 'worker_payload']));
    exit(1);
}

$server = [
    'CONTENT_TYPE' => 'application/json',
    'HTTP_ACCEPT' => 'application/json',
    'REMOTE_ADDR' => '10.9.8.7',
];

if (isset($payload['authorization']) && is_string($payload['authorization'])) {
    $server['HTTP_AUTHORIZATION'] = $payload['authorization'];
}

$request = Request::create(
    (string) ($payload['uri'] ?? '/'),
    (string) ($payload['method'] ?? 'POST'),
    [],
    [],
    [],
    $server,
    json_encode($payload['body'] ?? []),
);

$response = $kernel->handle($request);
$decoded = json_decode((string) $response->getContent(), true);
$code = null;

if (is_array($decoded) && isset($decoded['error']['code']) && is_string($decoded['error']['code'])) {
    $code = $decoded['error']['code'];
}

file_put_contents($argv[2], json_encode([
    'status' => $response->getStatusCode(),
    'code' => $code,
]));

$kernel->terminate($request, $response);
