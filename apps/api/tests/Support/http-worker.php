<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$payload = json_decode((string) file_get_contents($argv[1]), true);

if (! is_array($payload)) {
    file_put_contents($argv[2], json_encode(['status' => 0, 'code' => 'worker_payload']));
    exit(1);
}

$barrierInvoked = false;

if (isset($payload['barrier']) && is_array($payload['barrier'])) {
    $barrierName = $payload['barrier']['name'] ?? null;
    $barrierKey = $payload['barrier']['key'] ?? null;

    if (is_string($barrierName) && is_numeric($barrierKey)) {
        $key = (int) $barrierKey;
        $barrierInvoked = false;
        config(['medifind.testing.barriers.'.$barrierName => static function () use ($key, &$barrierInvoked): void {
            $barrierInvoked = true;
            DB::select('select pg_advisory_lock(?)', [$key]);
        }]);
        DB::statement("set lock_timeout = '30s'");
    }
}

$server = [
    'CONTENT_TYPE' => 'application/json',
    'HTTP_ACCEPT' => 'application/json',
    'REMOTE_ADDR' => '10.9.8.7',
];

if (isset($payload['authorization']) && is_string($payload['authorization'])) {
    $server['HTTP_AUTHORIZATION'] = $payload['authorization'];
}

if (isset($payload['headers']) && is_array($payload['headers'])) {
    foreach ($payload['headers'] as $name => $value) {
        if (is_string($name) && is_string($value)) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }
    }
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

$id = null;

if (is_array($decoded) && isset($decoded['data']['id']) && is_string($decoded['data']['id'])) {
    $id = $decoded['data']['id'];
}

file_put_contents($argv[2], json_encode([
    'status' => $response->getStatusCode(),
    'code' => $code,
    'id' => $id,
    'barrier_invoked' => $barrierInvoked,
]));

$kernel->terminate($request, $response);
