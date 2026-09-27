<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$payload = json_decode((string) file_get_contents($argv[1]), true);
$publicId = is_array($payload) ? ($payload['order_id'] ?? null) : null;
$changed = false;

if (is_string($publicId)) {
    DB::transaction(function () use ($publicId, &$changed): void {
        $order = Order::query()->where('public_id', $publicId)->lockForUpdate()->first();

        if ($order instanceof Order && $order->status === OrderStatus::PendingPharmacyReview) {
            $order->status = OrderStatus::ReadyForPickup;
            $order->save();
            $changed = true;
        }
    });
}

file_put_contents($argv[2], json_encode(['changed' => $changed]));
