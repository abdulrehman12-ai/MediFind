<?php

namespace Tests\Feature;

use App\Catalog\MedicineCatalog;
use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Inventory\InventoryBook;
use App\Models\Medicine;
use App\Models\Order;
use App\Models\Pharmacy;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\User;
use App\Pharmacy\PharmacyWorkflow;
use App\Prescriptions\PrescriptionWorkflow;
use App\Prescriptions\SelectionIdentity;
use Database\Seeders\CitySeeder;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

class PickupOrderingConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected array $connectionsToTransact = [];

    private User $admin;

    private User $customer;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CitySeeder::class);
        $this->admin = $this->user(UserRole::Admin, 'admin-race@example.test', null);
        $this->customer = $this->user(UserRole::Customer, 'race@example.test', '+923001110601');
        foreach (['upload', 'edit', 'share', 'read'] as $action) {
            RateLimiter::clear('prescription:'.$action.':'.$this->customer->id);
        }
        $this->token = $this->customer->createToken('customer', ['customer:access'], now()->addDay())->plainTextToken;
    }

    protected function tearDown(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        parent::tearDown();
    }

    public function test_concurrent_submits_with_one_key_create_one_order(): void
    {
        $pharmacy = $this->pharmacy('Race Pharmacy');
        $medicine = $this->medicine('Paracetamol');
        $this->stock($pharmacy, $medicine);
        $this->withToken($this->token)->putJson('/api/v1/cart', [
            'pharmacy_id' => $pharmacy->id,
            'mode' => 'catalog_items',
            'items' => [['medicine_id' => $medicine->id, 'quantity' => 1]],
        ])->assertOk();
        $body = [
            'uri' => '/api/v1/orders',
            'method' => 'POST',
            'authorization' => 'Bearer '.$this->token,
            'headers' => ['Idempotency-Key' => 'race-key-00000001'],
            'body' => ['cart_version' => 1],
        ];

        $results = $this->parallel([$body, $body]);
        $ids = [];

        foreach ($results as $result) {
            $this->assertContains($result['status'], [200, 201]);
            $this->assertIsString($result['id']);
            $ids[] = $result['id'];
        }

        $this->assertCount(1, array_unique($ids));
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, DB::table('order_status_events')->where('event', 'submitted')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'order_submitted')->count());
        $this->assertSame(8, (int) DB::table('pharmacy_inventory')->where('medicine_id', $medicine->id)->value('quantity_on_hand'));
    }

    public function test_concurrent_cancel_and_ready_transition_leave_one_status(): void
    {
        $pharmacy = $this->pharmacy('Race Cancel Pharmacy');
        $medicine = $this->medicine('Ibuprofen');
        $this->stock($pharmacy, $medicine);
        $this->withToken($this->token)->putJson('/api/v1/cart', [
            'pharmacy_id' => $pharmacy->id,
            'mode' => 'catalog_items',
            'items' => [['medicine_id' => $medicine->id, 'quantity' => 1]],
        ])->assertOk();
        $created = $this->withToken($this->token)->withHeader('Idempotency-Key', 'race-key-00000002')->postJson('/api/v1/orders', [
            'cart_version' => 1,
        ])->assertCreated();
        $publicId = (string) $created->json('data.id');

        $input = tempnam(sys_get_temp_dir(), 'mf-race-in');
        $output = tempnam(sys_get_temp_dir(), 'mf-race-out');
        $this->assertIsString($input);
        $this->assertIsString($output);
        file_put_contents($input, json_encode(['order_id' => $publicId]));
        $race = proc_open([PHP_BINARY, base_path('tests/Support/order-race-worker.php'), $input, $output], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $racePipes, base_path());
        $this->assertIsResource($race);
        fclose($racePipes[0]);

        $cancelInput = tempnam(sys_get_temp_dir(), 'mf-in');
        $cancelOutput = tempnam(sys_get_temp_dir(), 'mf-out');
        $this->assertIsString($cancelInput);
        $this->assertIsString($cancelOutput);
        file_put_contents($cancelInput, json_encode([
            'uri' => '/api/v1/orders/'.$publicId.'/cancel',
            'method' => 'POST',
            'authorization' => 'Bearer '.$this->token,
            'body' => [],
        ]));
        $cancel = proc_open([PHP_BINARY, base_path('tests/Support/http-worker.php'), $cancelInput, $cancelOutput], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $cancelPipes, base_path());
        $this->assertIsResource($cancel);
        fclose($cancelPipes[0]);

        foreach ([[$race, $racePipes, $input, $output], [$cancel, $cancelPipes, $cancelInput, $cancelOutput]] as [$process, $pipes, $in, $out]) {
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            @unlink((string) $in);
        }

        $cancelBody = json_decode((string) file_get_contents((string) $cancelOutput), true);
        @unlink((string) $cancelOutput);
        $status = (string) DB::table('orders')->where('public_id', $publicId)->value('status');
        $cancelledEvents = DB::table('order_status_events')->where('event', 'cancelled')->count();

        if ($status === 'cancelled') {
            $this->assertSame(200, (int) ($cancelBody['status'] ?? 0));
            $this->assertSame(1, $cancelledEvents);
        } else {
            $this->assertSame('ready_for_pickup', $status);
            $this->assertSame(409, (int) ($cancelBody['status'] ?? 0));
            $this->assertSame(0, $cancelledEvents);
        }

        $this->assertNotSame('pending_pharmacy_review', $status);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_concurrent_withdraw_and_submit_leave_no_confirmable_order(): void
    {
        $pharmacy = $this->pharmacy('Race Share Pharmacy');
        $medicine = $this->medicine('Cetirizine');
        $this->stock($pharmacy, $medicine);
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
        app(PrescriptionWorkflow::class)->share($this->customer, $prescription, 'full_image', (string) $pharmacy->id);
        $this->withToken($this->token)->putJson('/api/v1/cart', [
            'pharmacy_id' => $pharmacy->id,
            'mode' => 'prescription_full_image',
            'prescription_id' => $prescription->public_id,
            'items' => [],
        ])->assertOk();

        $results = $this->parallel([
            [
                'uri' => '/api/v1/orders',
                'method' => 'POST',
                'authorization' => 'Bearer '.$this->token,
                'headers' => ['Idempotency-Key' => 'race-key-00000003'],
                'body' => ['cart_version' => 1],
            ],
            [
                'uri' => '/api/v1/prescriptions/'.$prescription->public_id.'/withdraw',
                'method' => 'POST',
                'authorization' => 'Bearer '.$this->token,
                'body' => [],
            ],
        ]);

        $this->assertCount(2, $results);
        $this->assertSame(0, Order::query()->where('prescription_usable', true)->count());
        $this->assertSame('withdrawn', $prescription->refresh()->share_state);
    }

    public function test_share_and_cart_replacement_do_not_deadlock(): void
    {
        $pharmacy = $this->pharmacy('Race Select Pharmacy');
        $medicine = $this->medicine('Loratadine');
        $this->stock($pharmacy, $medicine);
        $prescription = $this->selectedUnshared($medicine);
        $results = $this->overlap(81021, [
            'script' => 'http-worker.php',
            'uri' => '/api/v1/prescriptions/'.$prescription->public_id.'/share',
            'method' => 'POST',
            'authorization' => 'Bearer '.$this->token,
            'body' => ['mode' => 'selected_items', 'pharmacy_id' => $pharmacy->id],
        ], [
            'script' => 'http-worker.php',
            'uri' => '/api/v1/cart',
            'method' => 'PUT',
            'authorization' => 'Bearer '.$this->token,
            'body' => [
                'pharmacy_id' => $pharmacy->id,
                'mode' => 'prescription_selected_items',
                'prescription_id' => $prescription->public_id,
                'items' => [['medicine_id' => $medicine->id, 'quantity' => 1]],
            ],
        ]);

        $this->assertSame(200, $results[0]['status']);
        $this->assertSame(200, $results[1]['status']);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(
            strtolower((string) $medicine->id),
            strtolower((string) DB::table('customer_cart_items')->value('medicine_id')),
        );
        $this->assertSame(
            app(SelectionIdentity::class)->fingerprint($medicine->refresh()),
            trim((string) DB::table('prescription_items')->where('prescription_id', $prescription->id)->value('selected_fingerprint')),
        );
    }

    public function test_correction_and_submit_do_not_deadlock_or_accept_a_stale_selection(): void
    {
        $pharmacy = $this->pharmacy('Race Correct Pharmacy');
        $medicine = $this->medicine('Cetirizine');
        $this->stock($pharmacy, $medicine);
        $prescription = $this->selectedUnshared($medicine);
        $item = PrescriptionItem::query()->where('prescription_id', $prescription->id)->firstOrFail();
        app(PrescriptionWorkflow::class)->share($this->customer, $prescription->refresh(), 'selected_items', (string) $pharmacy->id);
        $this->withToken($this->token)->putJson('/api/v1/cart', [
            'pharmacy_id' => $pharmacy->id,
            'mode' => 'prescription_selected_items',
            'prescription_id' => $prescription->public_id,
            'items' => [['medicine_id' => $medicine->id, 'quantity' => 1]],
        ])->assertOk();

        $results = $this->overlap(81022, [
            'script' => 'http-worker.php',
            'uri' => '/api/v1/prescriptions/'.$prescription->public_id.'/items/'.$item->public_id,
            'method' => 'PATCH',
            'authorization' => 'Bearer '.$this->token,
            'body' => ['selected' => true, 'selected_medicine_id' => $medicine->id],
        ], [
            'script' => 'http-worker.php',
            'uri' => '/api/v1/orders',
            'method' => 'POST',
            'authorization' => 'Bearer '.$this->token,
            'headers' => ['Idempotency-Key' => 'race-key-00000022'],
            'body' => ['cart_version' => 1],
        ]);

        $this->assertSame(200, $results[0]['status']);
        $this->assertSame(409, $results[1]['status']);
        $this->assertSame('prescription_unavailable', $results[1]['code']);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame('none', $prescription->refresh()->share_state);
    }

    public function test_withdrawal_and_confirmation_guard_do_not_deadlock(): void
    {
        $pharmacy = $this->pharmacy('Race Guard Pharmacy');
        $medicine = $this->medicine('Amoxicillin');
        $this->stock($pharmacy, $medicine);
        $prescription = $this->selectedUnshared($medicine);
        app(PrescriptionWorkflow::class)->share($this->customer, $prescription->refresh(), 'selected_items', (string) $pharmacy->id);
        $this->withToken($this->token)->putJson('/api/v1/cart', [
            'pharmacy_id' => $pharmacy->id,
            'mode' => 'prescription_selected_items',
            'prescription_id' => $prescription->public_id,
            'items' => [['medicine_id' => $medicine->id, 'quantity' => 1]],
        ])->assertOk();
        $created = $this->withToken($this->token)->withHeader('Idempotency-Key', 'race-key-00000023')->postJson('/api/v1/orders', [
            'cart_version' => 1,
        ])->assertCreated();
        $orderId = (string) Order::query()->where('public_id', $created->json('data.id'))->value('id');

        $results = $this->overlap(81023, [
            'script' => 'http-worker.php',
            'uri' => '/api/v1/prescriptions/'.$prescription->public_id.'/withdraw',
            'method' => 'POST',
            'authorization' => 'Bearer '.$this->token,
            'body' => [],
        ], [
            'script' => 'confirmation-guard-worker.php',
            'order_id' => $orderId,
        ]);

        $this->assertSame(200, $results[0]['status']);
        $this->assertSame(200, $results[1]['status']);
        $this->assertSame('prescription_unusable', $results[1]['block']);
        $this->assertFalse((bool) DB::table('orders')->where('id', $orderId)->value('prescription_usable'));
        $this->assertSame('withdrawn', $prescription->refresh()->share_state);
        $this->assertSame('pending_pharmacy_review', (string) DB::table('orders')->where('id', $orderId)->value('status'));
        $this->assertSame(1, Order::query()->count());
    }

    private function pharmacy(string $name): Pharmacy
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

    private function medicine(string $generic): Medicine
    {
        $catalog = app(MedicineCatalog::class);
        $medicine = $catalog->create($this->admin, [
            'generic_name' => $generic,
            'dosage_form' => 'Tablet',
            'strength' => '500 mg',
            'manufacturer' => 'Example Labs',
            'brand_name' => 'Example',
        ]);
        $catalog->activate($this->admin, $medicine);

        return $medicine->refresh();
    }

    private function stock(Pharmacy $pharmacy, Medicine $medicine): void
    {
        app(InventoryBook::class)->save($this->admin, $pharmacy, [
            'medicine_id' => (string) $medicine->id,
            'stock_status' => 'in_stock',
            'quantity_on_hand' => 8,
            'unit_price_paisa' => 2500,
            'is_available_for_pickup' => true,
        ]);
    }

    private function selectedUnshared(Medicine $medicine): Prescription
    {
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
            'extracted_text' => 'selected line',
            'match_status' => 'exact',
            'medicine_id' => $medicine->id,
            'customer_selected' => false,
        ])->save();

        return app(PrescriptionWorkflow::class)->correct(
            $this->customer,
            $prescription,
            $item->refresh(),
            null,
            (string) $medicine->id,
            true,
        );
    }

    /**
     * @param  array<string, mixed>  $first
     * @param  array<string, mixed>  $second
     * @return list<array<string, mixed>>
     */
    private function overlap(int $barrierKey, array $first, array $second): array
    {
        $barrier = ['name' => 'after_prescription_lock', 'key' => $barrierKey];
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
            DB::purge('phase5_barrier');
        }
    }

    private function barrierConnection(): Connection
    {
        $name = (string) config('database.default');
        config(['database.connections.phase5_barrier' => config('database.connections.'.$name)]);
        DB::purge('phase5_barrier');

        return DB::connection('phase5_barrier');
    }

    /**
     * @param  list<mixed>  $bindings
     * @param  array{resource: resource, pipes: array<int, resource>, input: string, output: string}|null  $worker
     */
    private function waitForLock(string $sql, array $bindings = [], ?array $worker = null): void
    {
        $deadline = microtime(true) + 15;

        do {
            if (DB::selectOne($sql, $bindings) !== null) {
                return;
            }

            if ($worker !== null) {
                $early = json_decode((string) @file_get_contents($worker['output']), true);

                if (is_array($early) && array_key_exists('status', $early)) {
                    $this->fail('Worker finished before the advisory barrier. '.json_encode($early));
                }
            }

            usleep(20000);
        } while (microtime(true) < $deadline);

        $activity = DB::select('select wait_event_type, wait_event, state from pg_stat_activity where datname = current_database() and pid <> pg_backend_pid()');
        $this->fail('Timed out waiting for the lock barrier. '.json_encode($activity));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{resource: resource, pipes: array<int, resource>, input: string, output: string}
     */
    private function startWorker(array $payload): array
    {
        $script = (string) ($payload['script'] ?? 'http-worker.php');
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
            stream_get_contents($worker['pipes'][2]);
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            proc_close($worker['resource']);
            $body = json_decode((string) file_get_contents($worker['output']), true);
            @unlink($worker['input']);
            @unlink($worker['output']);

            if (! is_array($body)) {
                if ($requireBody) {
                    $this->fail('A race worker returned no result.');
                }

                $body = ['status' => 0, 'code' => 'unreadable'];
            }

            $decoded[] = $body;
        }

        return $decoded;
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
     * @param  list<array<string, mixed>>  $payloads
     * @return list<array{status: int, code: ?string, id: ?string}>
     */
    private function parallel(array $payloads): array
    {
        $processes = [];

        foreach ($payloads as $payload) {
            $input = tempnam(sys_get_temp_dir(), 'mf-in');
            $output = tempnam(sys_get_temp_dir(), 'mf-out');
            file_put_contents((string) $input, json_encode($payload));
            $process = proc_open([PHP_BINARY, base_path('tests/Support/http-worker.php'), (string) $input, (string) $output], [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, base_path());
            $this->assertIsResource($process);
            fclose($pipes[0]);
            $processes[] = [$process, $pipes, $input, $output];
        }

        $decoded = [];

        foreach ($processes as [$process, $pipes, $input, $output]) {
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $raw = json_decode((string) file_get_contents((string) $output), true);
            $decoded[] = [
                'status' => (int) ($raw['status'] ?? 0),
                'code' => isset($raw['code']) && is_string($raw['code']) ? $raw['code'] : null,
                'id' => isset($raw['id']) && is_string($raw['id']) ? $raw['id'] : null,
            ];
            @unlink((string) $input);
            @unlink((string) $output);
        }

        return $decoded;
    }
}
