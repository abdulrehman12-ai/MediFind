<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Exceptions\ApiProblem;
use App\Filament\Pharmacy\Pages\PickupOrders;
use App\Inventory\InventoryBook;
use App\Models\Order;
use App\Models\OrderReservation;
use App\Models\Pharmacy;
use App\Models\PharmacyInventory;
use App\Models\Prescription;
use App\Models\User;
use App\Orders\FulfillmentService;
use App\Orders\OrderService;
use App\Orders\QuoteService;
use App\Prescriptions\PrescriptionWorkflow;
use App\Support\TestingBarrier;
use Filament\Facades\Filament;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$app->instance('request', Request::create('/phase6-worker', 'POST'));

$payload = json_decode((string) file_get_contents($argv[1]), true);

if (! is_array($payload)) {
    file_put_contents($argv[2], json_encode(['ok' => false, 'error' => 'payload']));
    exit(1);
}

if (isset($payload['now']) && is_string($payload['now'])) {
    Carbon::setTestNow($payload['now']);
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

try {
    $result = match ($payload['action'] ?? '') {
        'confirm' => confirm($payload),
        'collect' => collect($payload),
        'not_collected' => noShow($payload),
        'cancel' => cancel($payload),
        'withdraw' => withdraw($payload),
        'read_order_file' => readOrderFile($payload),
        'edit_inventory' => editInventory($payload),
        'insert_reservation' => insertReservation($payload),
        'propose' => propose($payload),
        default => ['ok' => false, 'error' => 'action'],
    };
    file_put_contents($argv[2], json_encode($result));
} catch (\Throwable $exception) {
    $code = $exception instanceof ApiProblem ? $exception->errorCode : ($exception instanceof ValidationException ? 'validation_failed' : $exception::class);
    file_put_contents($argv[2], json_encode([
        'ok' => false,
        'code' => $code,
        'error' => $exception->getMessage(),
    ]));
}

function confirm(array $payload): array
{
    $order = order($payload);
    $user = User::query()->findOrFail($payload['user_id']);
    $pharmacy = Pharmacy::query()->findOrFail($order->pharmacy_id);
    app(FulfillmentService::class)->confirm($user, $pharmacy, (string) $order->public_id);

    return snapshot((string) $order->id);
}

function collect(array $payload): array
{
    $order = order($payload);
    $user = User::query()->findOrFail($payload['user_id']);
    $pharmacy = Pharmacy::query()->findOrFail($order->pharmacy_id);
    app(FulfillmentService::class)->collect($user, $pharmacy, (string) $order->public_id, (int) $payload['cash']);

    return snapshot((string) $order->id);
}

function noShow(array $payload): array
{
    $order = order($payload);
    $user = User::query()->findOrFail($payload['user_id']);
    $pharmacy = Pharmacy::query()->findOrFail($order->pharmacy_id);
    app(FulfillmentService::class)->notCollected($user, $pharmacy, (string) $order->public_id, (string) $payload['reason']);

    return snapshot((string) $order->id);
}

function cancel(array $payload): array
{
    $order = order($payload);
    $customer = User::query()->findOrFail($order->customer_id);
    app(OrderService::class)->cancel($customer, (string) $order->public_id, 'Changed my mind');

    return snapshot((string) $order->id);
}

function readOrderFile(array $payload): array
{
    $user = User::query()->findOrFail($payload['user_id']);
    $pharmacy = Pharmacy::query()->findOrFail($payload['pharmacy_id']);
    auth()->login($user);
    Filament::setCurrentPanel(Filament::getPanel('pharmacy'));
    Filament::setTenant($pharmacy, true);
    $response = (new PickupOrders)->viewPrescription((string) $payload['public_id']);
    ob_start();
    $response->sendContent();
    $body = (string) ob_get_clean();
    $digest = hash('sha256', $body);

    return [
        'ok' => true,
        'sha256' => $digest,
        'length' => strlen($body),
        'disposition' => (string) $response->headers->get('Content-Disposition'),
        'cache' => (string) $response->headers->get('Cache-Control'),
        'nosniff' => (string) $response->headers->get('X-Content-Type-Options'),
    ];
}

function withdraw(array $payload): array
{
    $customer = User::query()->findOrFail($payload['customer_id']);
    $prescription = Prescription::query()->findOrFail($payload['prescription_id']);
    app(PrescriptionWorkflow::class)->withdraw($customer, $prescription);

    return ['ok' => true];
}

function editInventory(array $payload): array
{
    $inventory = PharmacyInventory::query()->findOrFail($payload['inventory_id']);
    $user = User::query()->findOrFail($payload['user_id']);
    $pharmacy = Pharmacy::query()->findOrFail($inventory->pharmacy_id);
    app(InventoryBook::class)->save($user, $pharmacy, [
        'medicine_id' => (string) $inventory->medicine_id,
        'stock_status' => (string) $payload['stock_status'],
        'quantity_on_hand' => $payload['quantity_on_hand'],
        'unit_price_paisa' => (int) ($payload['unit_price_paisa'] ?? $inventory->unit_price_paisa),
        'is_available_for_pickup' => (bool) $payload['pickup'],
        'version' => (int) $inventory->version,
    ]);

    return ['ok' => true, 'quantity_on_hand' => (int) $inventory->refresh()->quantity_on_hand];
}

function insertReservation(array $payload): array
{
    DB::transaction(function () use ($payload): void {
        DB::table('order_reservations')->insert([
            'id' => (string) Str::uuid(),
            'order_id' => $payload['order_id'],
            'inventory_id' => $payload['inventory_id'],
            'medicine_id' => $payload['medicine_id'],
            'order_item_id' => $payload['order_item_id'],
            'quote_line_id' => null,
            'quantity' => (int) $payload['quantity'],
            'state' => 'active',
            'created_at' => now(),
        ]);
        TestingBarrier::wait('after_raw_reservation_insert');
    });

    return ['ok' => true];
}

function propose(array $payload): array
{
    $order = order($payload);
    $user = User::query()->findOrFail($payload['user_id']);
    $pharmacy = Pharmacy::query()->findOrFail($order->pharmacy_id);
    $quote = app(QuoteService::class)->propose($user, $pharmacy, (string) $order->public_id, $payload['lines']);

    return [
        'ok' => true,
        'quote_id' => $quote->id,
        'version' => $quote->version,
        'total_paisa' => (int) $quote->total_paisa,
        'state' => $quote->state,
    ];
}

function order(array $payload): Order
{
    $order = Order::query()->findOrFail($payload['order_id']);

    return $order instanceof Order ? $order : throw new \RuntimeException('missing order');
}

/**
 * @return array<string, mixed>
 */
function snapshot(string $orderId): array
{
    $order = Order::query()->findOrFail($orderId);
    $reservations = OrderReservation::query()->where('order_id', $orderId)->pluck('state')->all();
    $onHand = PharmacyInventory::query()
        ->where('pharmacy_id', $order->pharmacy_id)
        ->pluck('quantity_on_hand', 'medicine_id');

    return [
        'ok' => true,
        'status' => $order->status->value,
        'payment_status' => $order->payment_status,
        'reservations' => array_values($reservations),
        'on_hand' => $onHand,
    ];
}
