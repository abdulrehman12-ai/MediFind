<?php

namespace Tests\Feature;

use App\Catalog\MedicineCatalog;
use App\Enums\AccountStatus;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Exceptions\ApiProblem;
use App\Filament\Pharmacy\Pages\PickupOrders;
use App\Inventory\InventoryBook;
use App\Models\Medicine;
use App\Models\Order;
use App\Models\Pharmacy;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\User;
use App\Orders\FulfillmentService;
use App\Pharmacy\PharmacyWorkflow;
use App\Prescriptions\PrescriptionObjectStore;
use App\Prescriptions\PrescriptionWorkflow;
use Database\Seeders\CitySeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

class PharmacyFulfillmentConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [];

    private User $admin;

    private User $customer;

    private User $pharmacist;

    private string $token;

    private Pharmacy $pharmacy;

    private Medicine $medicine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CitySeeder::class);
        $this->admin = $this->user(UserRole::Admin, 'admin-phase6-race@example.test', null);
        $this->customer = $this->user(UserRole::Customer, 'phase6-race@example.test', '+923001110801');
        foreach (['upload', 'edit', 'share', 'read'] as $action) {
            RateLimiter::clear('prescription:'.$action.':'.$this->customer->id);
        }
        $this->token = $this->customer->createToken('customer', ['customer:access'], now()->addDay())->plainTextToken;
        $this->pharmacy = $this->eligiblePharmacy('Phase 6 Race');
        $this->pharmacist = $this->pharmacist('phase6-race-pharmacist@example.test');
        $this->medicine = $this->medicine();
        $this->stock(4, 2500);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Artisan::call('migrate:fresh', ['--force' => true]);
        parent::tearDown();
    }

    public function test_two_confirmations_of_one_order_create_one_reservation(): void
    {
        $order = $this->catalogOrder(1);
        $payload = [
            'script' => 'fulfillment-worker.php',
            'action' => 'confirm',
            'order_id' => $order->id,
            'user_id' => $this->pharmacist->id,
        ];
        $results = $this->overlap(61001, 'after_fulfillment_order_lock', $payload, $payload);

        $this->assertTrue($results[0]['ok'] ?? false, json_encode($results));
        $this->assertTrue($results[1]['ok'] ?? false, json_encode($results));
        $this->assertSame(1, DB::table('order_reservations')->where('order_id', $order->id)->count());
        $this->assertSame('active', DB::table('order_reservations')->where('order_id', $order->id)->value('state'));
        $this->assertSame(4, $this->onHand());
        $this->assertSame(1, DB::table('order_status_events')->where('order_id', $order->id)->where('event', 'confirmed')->count());
    }

    public function test_two_orders_cannot_reserve_the_same_last_units(): void
    {
        $first = $this->catalogOrder(3);
        $second = $this->catalogOrder(3);
        $left = ['script' => 'fulfillment-worker.php', 'action' => 'confirm', 'order_id' => $first->id, 'user_id' => $this->pharmacist->id];
        $right = ['script' => 'fulfillment-worker.php', 'action' => 'confirm', 'order_id' => $second->id, 'user_id' => $this->pharmacist->id];
        $results = $this->overlap(61002, 'after_confirmation_inventory_lock', $left, $right);
        $codes = array_map(static fn (array $result): string => (string) ($result['code'] ?? ($result['ok'] ?? false ? 'ok' : 'fail')), $results);

        $this->assertContains('ok', $codes, json_encode($results));
        $this->assertContains('insufficient_stock', $codes, json_encode($results));
        $this->assertSame(1, DB::table('order_reservations')->where('state', 'active')->count());
        $this->assertSame(4, $this->onHand());
        $this->assertSame(3, (int) DB::table('order_reservations')->where('state', 'active')->value('quantity'));
    }

    public function test_reservation_trigger_rejects_a_second_concurrent_active_insert(): void
    {
        $first = $this->catalogOrder(3);
        $second = $this->catalogOrder(3);
        $left = $this->rawReservation($first, 3);
        $right = $this->rawReservation($second, 3);
        $results = $this->overlap(61003, 'after_raw_reservation_insert', $left, $right);
        $ok = 0;
        $rejected = 0;

        foreach ($results as $result) {
            if (($result['ok'] ?? false) === true) {
                $ok++;
            }
            if (str_contains((string) ($result['error'] ?? ''), 'active reservations exceed')) {
                $rejected++;
            }
        }

        $this->assertSame(1, $ok, json_encode($results));
        $this->assertSame(1, $rejected, json_encode($results));
        $this->assertSame(3, (int) DB::table('order_reservations')->where('state', 'active')->sum('quantity'));
        $this->assertSame(4, $this->onHand());
    }

    public function test_cancel_and_confirm_leave_one_outcome(): void
    {
        $order = $this->catalogOrder(1);
        $cancel = ['script' => 'fulfillment-worker.php', 'action' => 'cancel', 'order_id' => $order->id];
        $confirm = ['script' => 'fulfillment-worker.php', 'action' => 'confirm', 'order_id' => $order->id, 'user_id' => $this->pharmacist->id];
        $results = $this->overlap(61004, 'after_cancel_order_lock', $cancel, $confirm);
        $order->refresh();

        $this->assertSame(OrderStatus::Cancelled, $order->status, json_encode($results));
        $this->assertSame(0, DB::table('order_reservations')->where('order_id', $order->id)->count());
        $this->assertSame(4, $this->onHand());
    }

    public function test_inventory_edit_and_confirmation_do_not_deadlock(): void
    {
        $order = $this->catalogOrder(3);
        $inventoryId = (string) DB::table('pharmacy_inventory')->where('medicine_id', $this->medicine->id)->value('id');
        $edit = [
            'script' => 'fulfillment-worker.php',
            'action' => 'edit_inventory',
            'inventory_id' => $inventoryId,
            'user_id' => $this->admin->id,
            'stock_status' => 'in_stock',
            'quantity_on_hand' => 1,
            'pickup' => true,
        ];
        $confirm = ['script' => 'fulfillment-worker.php', 'action' => 'confirm', 'order_id' => $order->id, 'user_id' => $this->pharmacist->id];
        $results = $this->overlap(61005, 'after_inventory_row_lock', $edit, $confirm);

        $this->assertSame(1, $this->onHand(), json_encode($results));
        $this->assertSame(0, DB::table('order_reservations')->where('order_id', $order->id)->count());
        $this->assertSame(OrderStatus::PendingPharmacyReview, $order->refresh()->status);
    }

    public function test_collection_and_withdrawal_leave_one_reservation_outcome(): void
    {
        $order = $this->readySelected();
        $collect = [
            'script' => 'fulfillment-worker.php',
            'action' => 'collect',
            'order_id' => $order->id,
            'user_id' => $this->pharmacist->id,
            'cash' => 2500,
        ];
        $withdraw = [
            'script' => 'fulfillment-worker.php',
            'action' => 'withdraw',
            'customer_id' => $this->customer->id,
            'prescription_id' => $order->prescription_id,
        ];
        $results = $this->overlap(61006, 'after_prescription_lock', $withdraw, $collect);
        $order->refresh();
        $states = DB::table('order_reservations')->where('order_id', $order->id)->pluck('state')->all();

        $this->assertSame(OrderStatus::Unfulfillable, $order->status, json_encode($results));
        $this->assertSame(['released'], $states);
        $this->assertSame(4, $this->onHand());
        $this->assertSame('unpaid', $order->payment_status);
    }

    public function test_collection_and_no_show_leave_one_terminal_state(): void
    {
        $verified = Carbon::parse((string) DB::table('pharmacy_inventory')->where('medicine_id', $this->medicine->id)->value('last_verified_at'))->utc();
        $clock = $verified->copy()->addSecond();
        Carbon::setTestNow($clock);
        $order = $this->catalogOrder(1);
        app(FulfillmentService::class)->confirm($this->pharmacist, $this->pharmacy, $order->public_id);
        $start = $clock->copy()->timezone('Asia/Karachi')->addHours(2)->addMinute()->startOfMinute();
        $end = $start->copy()->addHours(3);
        app(FulfillmentService::class)->markReady(
            $this->pharmacist,
            $this->pharmacy,
            $order->public_id,
            $start->format('Y-m-d H:i'),
            $end->format('Y-m-d H:i'),
        );
        $until = Carbon::parse((string) $order->refresh()->collect_until)->utc();
        $collect = [
            'script' => 'fulfillment-worker.php',
            'action' => 'collect',
            'order_id' => $order->id,
            'user_id' => $this->pharmacist->id,
            'cash' => 2500,
            'now' => $until->toIso8601String(),
        ];
        $noShow = [
            'script' => 'fulfillment-worker.php',
            'action' => 'not_collected',
            'order_id' => $order->id,
            'user_id' => $this->pharmacist->id,
            'reason' => 'Customer did not arrive',
            'now' => $until->toIso8601String(),
        ];
        $results = $this->overlap(61007, 'after_fulfillment_order_lock', $collect, $noShow);
        $order->refresh();
        $states = DB::table('order_reservations')->where('order_id', $order->id)->pluck('state')->all();

        $this->assertSame(OrderStatus::NotCollected, $order->status, json_encode($results));
        $this->assertSame(['released'], $states);
        $this->assertSame(4, $this->onHand());
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertNull($order->cash_collected_paisa);
        $this->assertSame(0, DB::table('order_status_events')->where('order_id', $order->id)->where('event', 'collected')->count());
        $this->assertSame(1, DB::table('order_status_events')->where('order_id', $order->id)->where('event', 'not_collected')->count());
    }

    public function test_quote_proposal_and_inventory_price_edit_do_not_deadlock(): void
    {
        $order = $this->catalogOrder(1);
        $inventoryId = (string) DB::table('pharmacy_inventory')->where('medicine_id', $this->medicine->id)->value('id');
        $propose = [
            'script' => 'fulfillment-worker.php',
            'action' => 'propose',
            'order_id' => $order->id,
            'user_id' => $this->pharmacist->id,
            'lines' => [[
                'medicine_id' => (string) $this->medicine->id,
                'quantity' => 1,
                'unit_price_paisa' => 2500,
            ]],
        ];
        $edit = [
            'script' => 'fulfillment-worker.php',
            'action' => 'edit_inventory',
            'inventory_id' => $inventoryId,
            'user_id' => $this->admin->id,
            'stock_status' => 'in_stock',
            'quantity_on_hand' => 4,
            'pickup' => true,
            'unit_price_paisa' => 3000,
        ];
        $results = $this->overlap(61008, 'after_quote_inventory_lock', $propose, $edit);

        $this->assertTrue($results[0]['ok'] ?? false, json_encode($results));
        $this->assertTrue($results[1]['ok'] ?? false, json_encode($results));
        $quotes = DB::table('order_quotes')->where('order_id', $order->id)->get();
        $this->assertCount(1, $quotes);
        $this->assertSame('open', $quotes[0]->state);
        $this->assertSame(2500, (int) $quotes[0]->total_paisa);
        $this->assertSame(2500, (int) DB::table('order_quote_lines')->where('quote_id', $quotes[0]->id)->value('unit_price_paisa'));
        $this->assertSame(3000, (int) DB::table('pharmacy_inventory')->where('id', $inventoryId)->value('unit_price_paisa'));
        $this->assertSame(4, $this->onHand());
    }

    /**
     * @return array<string, mixed>
     */
    private function rawReservation(Order $order, int $quantity): array
    {
        return [
            'script' => 'fulfillment-worker.php',
            'action' => 'insert_reservation',
            'order_id' => $order->id,
            'inventory_id' => (string) DB::table('pharmacy_inventory')->where('medicine_id', $this->medicine->id)->value('id'),
            'medicine_id' => (string) $this->medicine->id,
            'order_item_id' => (string) DB::table('order_items')->where('order_id', $order->id)->value('id'),
            'quantity' => $quantity,
        ];
    }

    private function readySelected(): Order
    {
        $workflow = app(PrescriptionWorkflow::class);
        $prescription = new Prescription;
        $prescription->forceFill([
            'customer_id' => $this->customer->id,
            'object_key' => 'rx/'.Str::uuid()->toString(),
            'display_name' => 'prescription',
            'mime_type' => 'image/jpeg',
            'byte_size' => 128,
            'sha256' => hash('sha256', Str::uuid()->toString()),
            'extraction_state' => 'queued',
            'extraction_version' => 1,
            'content_version' => 1,
            'share_state' => 'none',
            'object_state' => 'stored',
        ])->save();
        $prescription->refresh();
        $item = new PrescriptionItem;
        $item->forceFill([
            'prescription_id' => $prescription->id,
            'extraction_version' => 1,
            'line_index' => 0,
            'extracted_text' => 'not-a-catalog-line',
            'match_status' => 'exact',
            'medicine_id' => $this->medicine->id,
            'customer_selected' => false,
        ])->save();
        $workflow->correct($this->customer, $prescription, $item, null, (string) $this->medicine->id, true);
        $shared = $workflow->share($this->customer, $prescription->refresh(), 'selected_items', (string) $this->pharmacy->id);
        $workflow->review($this->pharmacist, $this->pharmacy, $shared->refresh(), (int) $shared->content_version, 'verified', null);
        $this->withToken($this->token)->putJson('/api/v1/cart', [
            'pharmacy_id' => $this->pharmacy->id,
            'mode' => 'prescription_selected_items',
            'prescription_id' => $shared->public_id,
            'items' => [['medicine_id' => $this->medicine->id, 'quantity' => 1]],
        ])->assertOk();
        $version = (int) $this->withToken($this->token)->getJson('/api/v1/cart')->json('data.version');
        $created = $this->withToken($this->token)->withHeader('Idempotency-Key', bin2hex(random_bytes(8)))->postJson('/api/v1/orders', [
            'cart_version' => $version,
        ])->assertCreated();
        $order = Order::query()->where('public_id', $created->json('data.id'))->firstOrFail();
        app(FulfillmentService::class)->confirm($this->pharmacist, $this->pharmacy, $order->public_id);

        return app(FulfillmentService::class)->markReady($this->pharmacist, $this->pharmacy, $order->public_id, '2026-12-01 10:00', '2026-12-01 18:00');
    }

    private function catalogOrder(int $quantity): Order
    {
        $this->withToken($this->token)->putJson('/api/v1/cart', [
            'pharmacy_id' => $this->pharmacy->id,
            'mode' => 'catalog_items',
            'items' => [['medicine_id' => $this->medicine->id, 'quantity' => $quantity]],
        ])->assertOk();
        $version = (int) $this->withToken($this->token)->getJson('/api/v1/cart')->json('data.version');
        $created = $this->withToken($this->token)->withHeader('Idempotency-Key', bin2hex(random_bytes(8)))->postJson('/api/v1/orders', [
            'cart_version' => $version,
        ])->assertCreated();

        return Order::query()->where('public_id', $created->json('data.id'))->firstOrFail();
    }

    private function onHand(): int
    {
        return (int) DB::table('pharmacy_inventory')->where('medicine_id', $this->medicine->id)->value('quantity_on_hand');
    }

    private function stock(int $quantity, int $price): void
    {
        app(InventoryBook::class)->save($this->admin, $this->pharmacy, [
            'medicine_id' => (string) $this->medicine->id,
            'stock_status' => 'in_stock',
            'quantity_on_hand' => $quantity,
            'unit_price_paisa' => $price,
            'is_available_for_pickup' => true,
        ]);
    }

    public function test_withdrawal_waits_until_the_order_file_authorization_commits(): void
    {
        $bytes = 'phase6-order-file-race';
        $prescription = new Prescription;
        $prescription->forceFill([
            'customer_id' => $this->customer->id,
            'object_key' => 'rx/'.Str::uuid()->toString(),
            'display_name' => 'prescription',
            'mime_type' => 'image/jpeg',
            'byte_size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'extraction_state' => 'queued',
            'extraction_version' => 1,
            'content_version' => 1,
            'share_state' => 'none',
            'object_state' => 'stored',
        ])->save();
        $prescription->refresh();
        app(PrescriptionObjectStore::class)->put((string) $prescription->object_key, $bytes);
        $workflow = app(PrescriptionWorkflow::class);
        $shared = $workflow->share($this->customer, $prescription, 'full_image', (string) $this->pharmacy->id);
        $workflow->review($this->pharmacist, $this->pharmacy, $shared->refresh(), (int) $shared->content_version, 'verified', null);
        $this->withToken($this->token)->putJson('/api/v1/cart', [
            'pharmacy_id' => $this->pharmacy->id,
            'mode' => 'prescription_full_image',
            'prescription_id' => $shared->public_id,
            'items' => [],
        ])->assertOk();
        $version = (int) $this->withToken($this->token)->getJson('/api/v1/cart')->json('data.version');
        $created = $this->withToken($this->token)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/orders', [
            'cart_version' => $version,
        ])->assertCreated();
        $order = Order::query()->where('public_id', $created->json('data.id'))->firstOrFail();

        $results = $this->overlap(61021, 'after_order_file_prescription_lock', [
            'script' => 'fulfillment-worker.php',
            'action' => 'read_order_file',
            'user_id' => $this->pharmacist->id,
            'pharmacy_id' => $this->pharmacy->id,
            'public_id' => $order->public_id,
        ], [
            'script' => 'fulfillment-worker.php',
            'action' => 'withdraw',
            'customer_id' => $this->customer->id,
            'prescription_id' => $prescription->id,
        ]);

        $this->assertTrue($results[0]['ok'] ?? false);
        $this->assertSame(hash('sha256', $bytes), $results[0]['sha256'] ?? null);
        $this->assertStringContainsString('no-store', (string) ($results[0]['cache'] ?? ''));
        $this->assertStringContainsString('private', (string) ($results[0]['cache'] ?? ''));
        $this->assertSame('nosniff', $results[0]['nosniff'] ?? null);
        $this->assertTrue($results[1]['ok'] ?? false);
        $this->assertFalse($order->refresh()->prescription_usable);
        $this->assertSame('withdrawn', $prescription->refresh()->share_state);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'prescription_file_read')->count());

        $this->actingAs($this->pharmacist, 'web');
        Filament::setCurrentPanel(Filament::getPanel('pharmacy'));
        Filament::setTenant($this->pharmacy, true);

        try {
            (new PickupOrders)->viewPrescription($order->public_id);
            $this->fail('The withdrawn order remained readable.');
        } catch (ApiProblem $exception) {
            $this->assertSame(404, $exception->status);
            $this->assertSame('not_found', $exception->errorCode);
        }

        $this->assertSame(1, DB::table('audit_events')->where('action', 'prescription_file_read')->count());
    }

    private function medicine(): Medicine
    {
        $catalog = app(MedicineCatalog::class);
        $medicine = $catalog->create($this->admin, [
            'generic_name' => 'Paracetamol',
            'dosage_form' => 'Tablet',
            'strength' => '500 mg',
            'manufacturer' => 'Example Labs',
            'brand_name' => 'Example',
            'prescription_required' => false,
            'controlled_drug' => false,
        ]);
        $catalog->activate($this->admin, $medicine);

        return $medicine->refresh();
    }

    private function pharmacist(string $email): User
    {
        $workflow = app(PharmacyWorkflow::class);
        $staff = $this->user(UserRole::PharmacyStaff, $email, null);
        $staff->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $staff->saveAppAuthenticationRecoveryCodes(['recovery-code-phase6-race']);
        $membership = $workflow->assignPharmacist($this->admin, $this->pharmacy, $staff, 'PH-'.Str::upper(Str::random(6)));
        $workflow->verifyCredential($this->admin, $membership, 'Reviewed pharmacist certificate');

        return $staff;
    }

    private function eligiblePharmacy(string $name): Pharmacy
    {
        $workflow = app(PharmacyWorkflow::class);
        $pharmacy = $workflow->create($this->admin, [
            'name' => $name,
            'address_text' => $name.' Susan Road',
            'latitude' => '31.450400',
            'longitude' => '73.135000',
            'license_number' => 'LIC-'.Str::upper(Str::random(8)),
            'phone_e164' => '+92300'.random_int(1000000, 9999999),
        ]);
        $workflow->verifyLicense($this->admin, $pharmacy, 'Reviewed license file '.$name);
        $workflow->approveLocation($this->admin, $pharmacy, 'Reviewed map pin');
        $workflow->approve($this->admin, $pharmacy->refresh());

        return $pharmacy->refresh();
    }

    private function user(UserRole $role, ?string $email, ?string $phone): User
    {
        return User::query()->forceCreate([
            'public_id' => (string) Str::uuid(),
            'email' => $email,
            'phone_e164' => $phone,
            'password' => $role === UserRole::Customer ? null : 'not-a-login-secret',
            'role' => $role,
            'status' => AccountStatus::Active,
        ]);
    }

    /**
     * @param  array<string, mixed>  $first
     * @param  array<string, mixed>  $second
     * @return list<array<string, mixed>>
     */
    private function overlap(int $barrierKey, string $barrierName, array $first, array $second): array
    {
        $barrier = ['name' => $barrierName, 'key' => $barrierKey];
        $first['barrier'] = $barrier;
        $second['barrier'] = $barrier;
        $gate = $this->barrierConnection();
        $gate->select('select pg_advisory_lock(?)', [$barrierKey]);
        $started = [];
        $released = false;

        try {
            $started[] = $this->startWorker($first);
            $this->waitForLock(
                "select 1 from pg_locks where locktype = 'advisory' and classid = 0 and objid = cast(? as oid) and granted = false",
                [$barrierKey],
                $started[0],
            );
            $started[] = $this->startWorker($second);
            $this->waitForLock(
                "select 1 from pg_stat_activity where datname = current_database() and state = 'active' and wait_event_type = 'Lock' and wait_event in ('tuple', 'transactionid')",
            );
            $gate->select('select pg_advisory_unlock(?)', [$barrierKey]);
            $released = true;

            return $this->finishWorkers($started);
        } catch (Throwable $exception) {
            if (! $released) {
                try {
                    $gate->select('select pg_advisory_unlock(?)', [$barrierKey]);
                } catch (Throwable) {
                }
            }

            $summary = $started === [] ? [] : $this->finishWorkers($started, false);

            throw new \RuntimeException($exception->getMessage().' workers='.json_encode($summary), 0, $exception);
        } finally {
            DB::purge('phase6_barrier');
        }
    }

    private function barrierConnection(): Connection
    {
        $name = (string) config('database.default');
        config(['database.connections.phase6_barrier' => config('database.connections.'.$name)]);
        DB::purge('phase6_barrier');

        return DB::connection('phase6_barrier');
    }

    /**
     * @param  list<mixed>  $bindings
     * @param  array{resource: resource, pipes: array<int, resource>, input: string, output: string}|null  $worker
     */
    private function waitForLock(string $sql, array $bindings = [], ?array $worker = null): void
    {
        $deadline = microtime(true) + 20;

        do {
            if (DB::selectOne($sql, $bindings) !== null) {
                return;
            }

            if ($worker !== null) {
                $early = json_decode((string) @file_get_contents($worker['output']), true);

                if (is_array($early) && array_key_exists('ok', $early)) {
                    $this->fail('Worker finished before the advisory barrier. '.json_encode($early));
                }
            }

            usleep(20000);
        } while (microtime(true) < $deadline);

        $activity = DB::select('select wait_event_type, wait_event, state, left(query, 180) as query from pg_stat_activity where datname = current_database() and pid <> pg_backend_pid()');
        $this->fail('Timed out waiting for the lock barrier. '.json_encode($activity));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{resource: resource, pipes: array<int, resource>, input: string, output: string}
     */
    private function startWorker(array $payload): array
    {
        $script = (string) ($payload['script'] ?? 'fulfillment-worker.php');
        unset($payload['script']);
        $input = tempnam(sys_get_temp_dir(), 'mf-in');
        $output = tempnam(sys_get_temp_dir(), 'mf-out');
        $this->assertIsString($input);
        $this->assertIsString($output);
        file_put_contents($input, json_encode($payload));
        $process = proc_open([PHP_BINARY, base_path('tests/Support/'.$script), $input, $output], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, base_path());
        $this->assertIsResource($process);
        fclose($pipes[0]);

        return ['resource' => $process, 'pipes' => $pipes, 'input' => $input, 'output' => $output];
    }

    /**
     * @param  list<array{resource: resource, pipes: array<int, resource>, input: string, output: string}>  $started
     * @return list<array<string, mixed>>
     */
    private function finishWorkers(array $started, bool $requireBody = true): array
    {
        $decoded = [];

        foreach ($started as $worker) {
            stream_get_contents($worker['pipes'][1]);
            $error = stream_get_contents($worker['pipes'][2]);
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            proc_close($worker['resource']);
            $body = json_decode((string) file_get_contents($worker['output']), true);
            @unlink($worker['input']);
            @unlink($worker['output']);

            if (! is_array($body)) {
                if ($requireBody) {
                    $this->fail('A race worker returned no result. '.$error);
                }

                $body = ['ok' => false, 'error' => $error];
            }

            $decoded[] = $body;
        }

        return $decoded;
    }
}
