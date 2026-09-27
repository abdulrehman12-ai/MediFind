<?php

declare(strict_types=1);

use App\Models\Order;
use App\Orders\OrderPrescriptionGuard;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$payload = json_decode((string) file_get_contents($argv[1]), true);

if (! is_array($payload) || ! is_string($payload['order_id'] ?? null)) {
    file_put_contents($argv[2], json_encode(['status' => 0, 'block' => null]));
    exit(1);
}

if (isset($payload['barrier']) && is_array($payload['barrier'])) {
    $barrierName = $payload['barrier']['name'] ?? null;
    $barrierKey = $payload['barrier']['key'] ?? null;

    if (is_string($barrierName) && is_numeric($barrierKey)) {
        $key = (int) $barrierKey;
        config(['medifind.testing.barriers.'.$barrierName => static function () use ($key): void {
            DB::select('select pg_advisory_lock(?)', [$key]);
        }]);
        DB::statement("set lock_timeout = '30s'");
    }
}

$order = Order::query()->whereKey($payload['order_id'])->first();

if (! $order instanceof Order) {
    file_put_contents($argv[2], json_encode(['status' => 404, 'block' => 'missing']));
    exit(0);
}

try {
    $block = app(OrderPrescriptionGuard::class)->prescriptionConfirmationBlock($order);
    file_put_contents($argv[2], json_encode(['status' => 200, 'block' => $block]));
} catch (Throwable $exception) {
    file_put_contents($argv[2], json_encode([
        'status' => 500,
        'block' => null,
        'error' => $exception::class,
    ]));
    exit(1);
}
