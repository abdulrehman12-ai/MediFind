<?php

namespace Tests\Feature;

use App\Catalog\MedicineCatalog;
use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Identity\Otp\InMemoryOtpProvider;
use App\Identity\Otp\OtpHasher;
use App\Identity\Otp\OtpRedis;
use App\Identity\Phone\PakistaniMobileNormalizer;
use App\Inventory\InventoryBook;
use App\Models\Pharmacy;
use App\Models\User;
use App\Pharmacy\PharmacyWorkflow;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CustomerJourneyDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(OtpRedis::class)->deleteNamespace();

        parent::tearDown();
    }

    public function test_otp_session_finds_only_a_pharmacy_that_can_supply_every_selected_medicine(): void
    {
        $this->seed(CitySeeder::class);
        $token = $this->issueToken('03009876001');
        $otherToken = $this->issueToken('03009876002');

        $this->getJson('/api/v1/medicines?q=auditjourney')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');

        $address = $this->withCustomerToken($token)->postJson('/api/v1/me/addresses', [
            'label' => 'Home',
            'address_text' => 'Susan Road, Faisalabad',
            'latitude' => 31.450000,
            'longitude' => 73.135000,
        ]);
        $address->assertCreated()
            ->assertJsonPath('data.city', 'Faisalabad');
        $addressId = (string) $address->json('data.id');

        $admin = User::query()->forceCreate([
            'public_id' => (string) Str::uuid(),
            'email' => 'audit-journey-admin@example.test',
            'phone_e164' => null,
            'password' => 'not-a-login-secret',
            'role' => UserRole::Admin,
            'status' => AccountStatus::Active,
        ]);
        $catalog = app(MedicineCatalog::class);
        $workflow = app(PharmacyWorkflow::class);
        $tablet = $catalog->create($admin, [
            'generic_name' => 'Auditjourney',
            'dosage_form' => 'Tablet',
            'strength' => '10 mg',
            'manufacturer' => 'Alpha Labs',
            'brand_name' => null,
        ]);
        $capsule = $catalog->create($admin, [
            'generic_name' => 'Auditjourney',
            'dosage_form' => 'Capsule',
            'strength' => '20 mg',
            'manufacturer' => 'Alpha Labs',
            'brand_name' => null,
        ]);
        $catalog->activate($admin, $tablet);
        $catalog->activate($admin, $capsule);
        $full = $this->eligiblePharmacy($workflow, $admin, 'Journey Full Pharmacy', '31.450400', '73.135000', '+923009990001');
        $partial = $this->eligiblePharmacy($workflow, $admin, 'Journey Partial Pharmacy', '31.451000', '73.135000', '+923009990002');
        $this->stock($admin, $full, $tablet, true);
        $this->stock($admin, $full, $capsule, true);
        $this->stock($admin, $partial, $tablet, true);

        $search = $this->withCustomerToken($token)
            ->withHeader('X-Request-Id', 'spoofed-client-id')
            ->getJson('/api/v1/medicines?q=auditjourney');
        $search->assertOk();
        $searchId = $this->assertServerRequestId($search);
        $this->assertEqualsCanonicalizing(
            ['has_more', 'page', 'per_page', 'request_id'],
            array_keys($search->json('meta')),
        );
        $this->assertSame(1, $search->json('meta.page'));
        $this->assertSame(20, $search->json('meta.per_page'));
        $this->assertFalse($search->json('meta.has_more'));
        $variants = $search->json('data');
        $this->assertCount(2, $variants);
        foreach ($variants as $variant) {
            $this->assertEqualsCanonicalizing(
                ['brand_name', 'dosage_form', 'generic_name', 'id', 'manufacturer', 'prescription_required', 'strength'],
                array_keys($variant),
            );
            $this->assertSame('Auditjourney', $variant['generic_name']);
        }
        $medicineIds = array_column($variants, 'id');
        sort($medicineIds);

        $found = $this->availability($token, $medicineIds, $addressId);
        $found->assertOk();
        $this->assertServerRequestId($found);
        $this->assertNotSame($searchId, $found->json('meta.request_id'));
        $this->assertEqualsCanonicalizing(
            ['confirmation', 'distance_method', 'freshness_hours', 'has_more', 'page', 'per_page', 'radius_meters', 'request_id'],
            array_keys($found->json('meta')),
        );
        $this->assertSame('straight_line', $found->json('meta.distance_method'));
        $this->assertSame(10000, $found->json('meta.radius_meters'));
        $this->assertSame(24, $found->json('meta.freshness_hours'));
        $this->assertSame(
            'Stock and price require pharmacy confirmation and are not a reservation.',
            $found->json('meta.confirmation'),
        );
        $this->assertSame([(string) $full->id], array_column($found->json('data'), 'id'));
        $pharmacy = $found->json('data.0');
        $this->assertEqualsCanonicalizing(
            ['address', 'distance_meters', 'distance_method', 'id', 'latitude', 'longitude', 'medicines', 'name', 'open_now'],
            array_keys($pharmacy),
        );
        $this->assertSame('31.450400', $pharmacy['latitude']);
        $this->assertSame('73.135000', $pharmacy['longitude']);
        $this->assertSame('straight_line', $pharmacy['distance_method']);
        $this->assertIsInt($pharmacy['distance_meters']);
        $this->assertGreaterThan(0, $pharmacy['distance_meters']);
        $this->assertNull($pharmacy['open_now']);
        $returnedIds = array_column($pharmacy['medicines'], 'id');
        sort($returnedIds);
        $this->assertSame($medicineIds, $returnedIds);
        foreach ($pharmacy['medicines'] as $summary) {
            $this->assertEqualsCanonicalizing(
                ['brand_name', 'dosage_form', 'generic_name', 'id', 'last_verified_at', 'manufacturer', 'prescription_required', 'stock_status', 'strength', 'unit_price_paisa'],
                array_keys($summary),
            );
            $this->assertSame('in_stock', $summary['stock_status']);
        }
        $this->assertNotContains((string) $partial->id, array_column($found->json('data'), 'id'));

        $this->availability($otherToken, $medicineIds, $addressId)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        $workflow->suspend($admin, $full->refresh(), 'Closed for the audit journey');
        $after = $this->availability($token, $medicineIds, $addressId);
        $after->assertOk();
        $this->assertServerRequestId($after);
        $this->assertSame('straight_line', $after->json('meta.distance_method'));
        $this->assertSame(
            'Stock and price require pharmacy confirmation and are not a reservation.',
            $after->json('meta.confirmation'),
        );
        $this->assertSame([], array_column($after->json('data'), 'id'));

        $workflow->reinstate($admin, $full->refresh());
        $restored = $this->availability($token, $medicineIds, $addressId);
        $restored->assertOk();
        $this->assertSame([(string) $full->id], array_column($restored->json('data'), 'id'));
        $restoredIds = array_column($restored->json('data.0.medicines'), 'id');
        sort($restoredIds);
        $this->assertSame($medicineIds, $restoredIds);
        $this->assertNotContains((string) $partial->id, array_column($restored->json('data'), 'id'));
    }

    public function test_otp_discovery_cart_submit_and_cancel_keeps_an_unpaid_estimate(): void
    {
        $this->seed(CitySeeder::class);
        $token = $this->issueToken('03009876011');

        $admin = User::query()->forceCreate([
            'public_id' => (string) Str::uuid(),
            'email' => 'pickup-journey-admin@example.test',
            'phone_e164' => null,
            'password' => 'not-a-login-secret',
            'role' => UserRole::Admin,
            'status' => AccountStatus::Active,
        ]);
        $catalog = app(MedicineCatalog::class);
        $workflow = app(PharmacyWorkflow::class);
        $medicine = $catalog->create($admin, [
            'generic_name' => 'Pickupjourney',
            'dosage_form' => 'Tablet',
            'strength' => '500 mg',
            'manufacturer' => 'Alpha Labs',
            'brand_name' => 'Journeybrand',
        ]);
        $catalog->activate($admin, $medicine);
        $pharmacy = $this->eligiblePharmacy($workflow, $admin, 'Journey Pickup Pharmacy', '31.450400', '73.135000', '+923009990011');
        $this->stock($admin, $pharmacy, $medicine, true);
        $medicineId = strtolower((string) $medicine->id);
        $inventory = DB::table('pharmacy_inventory')->where('medicine_id', $medicineId)->first();
        $this->assertNotNull($inventory);
        $quantityBefore = (int) $inventory->quantity_on_hand;
        $versionBefore = (int) $inventory->version;

        $address = $this->withCustomerToken($token)->postJson('/api/v1/me/addresses', [
            'label' => 'Home',
            'address_text' => 'Susan Road, Faisalabad',
            'latitude' => 31.450000,
            'longitude' => 73.135000,
        ])->assertCreated();
        $addressId = (string) $address->json('data.id');

        $search = $this->withCustomerToken($token)->getJson('/api/v1/medicines?q=pickupjourney')->assertOk();
        $this->assertSame([$medicineId], array_column($search->json('data'), 'id'));
        $found = $search->json('data.0');
        $this->assertSame('Pickupjourney', $found['generic_name']);
        $this->assertSame('Journeybrand', $found['brand_name']);
        $this->assertSame('500 mg', $found['strength']);
        $this->assertSame('Tablet', $found['dosage_form']);
        $this->assertSame('Alpha Labs', $found['manufacturer']);

        $availability = $this->availability($token, [$medicineId], $addressId)->assertOk();
        $this->assertSame([(string) $pharmacy->id], array_column($availability->json('data'), 'id'));
        $this->assertSame('Faisalabad', $address->json('data.city'));
        $this->assertSame(1500, $availability->json('data.0.medicines.0.unit_price_paisa'));
        $this->assertSame('in_stock', $availability->json('data.0.medicines.0.stock_status'));

        $cart = $this->withCustomerToken($token)->putJson('/api/v1/cart', [
            'pharmacy_id' => $pharmacy->id,
            'mode' => 'catalog_items',
            'customer_note' => 'Counter pickup',
            'items' => [['medicine_id' => $medicineId, 'quantity' => 1]],
        ])->assertOk();
        $cart->assertJsonPath('data.version', 1);
        $cart->assertJsonPath('data.pharmacy_id', strtolower((string) $pharmacy->id));
        $cart->assertJsonPath('data.items.0.unit_price_paisa', 1500);
        $cart->assertJsonPath('data.amounts_are_estimates', true);

        $key = 'journey-key-00001';
        $created = $this->withCustomerToken($token)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/orders', ['cart_version' => 1])
            ->assertCreated();
        $orderId = (string) $created->json('data.id');
        $created->assertJsonPath('data.status', 'pending_pharmacy_review');
        $created->assertJsonPath('data.payment_method', 'cash_on_pickup');
        $created->assertJsonPath('data.payment_status', 'unpaid');
        $created->assertJsonPath('data.amounts_are_estimates', true);
        $created->assertJsonPath('data.estimate_total_paisa', 1500);
        $created->assertJsonPath('data.confirmed_total_paisa', null);
        $created->assertJsonPath('data.cash_collected_paisa', null);
        $created->assertJsonPath('data.customer_note', 'Counter pickup');
        $created->assertJsonPath('data.pharmacy_id', strtolower((string) $pharmacy->id));
        $created->assertJsonPath('data.request_mode', 'catalog_items');
        $created->assertJsonPath('data.items.0.medicine_id', $medicineId);
        $created->assertJsonPath('data.items.0.generic_name', 'Pickupjourney');
        $created->assertJsonPath('data.items.0.brand_name', 'Journeybrand');
        $created->assertJsonPath('data.items.0.strength', '500 mg');
        $created->assertJsonPath('data.items.0.dosage_form', 'Tablet');
        $created->assertJsonPath('data.items.0.manufacturer', 'Alpha Labs');
        $created->assertJsonPath('data.items.0.quantity', 1);
        $created->assertJsonPath('data.items.0.unit_price_paisa', 1500);
        $created->assertJsonPath('data.items.0.line_estimate_paisa', 1500);
        $created->assertJsonPath('data.timeline.0.event', 'submitted');
        $created->assertJsonPath('data.timeline.0.status', 'pending_pharmacy_review');
        $this->assertMatchesRegularExpression('/^MF[A-Z2-9]{8}$/', (string) $created->json('data.reference'));

        $shown = $this->withCustomerToken($token)->getJson('/api/v1/orders/'.$orderId)->assertOk();
        $shown->assertJsonPath('data.id', $orderId);
        $shown->assertJsonPath('data.status', 'pending_pharmacy_review');
        $shown->assertJsonPath('data.estimate_total_paisa', 1500);
        $shown->assertJsonPath('data.payment_method', 'cash_on_pickup');
        $shown->assertJsonPath('data.payment_status', 'unpaid');

        $cancelled = $this->withCustomerToken($token)
            ->postJson('/api/v1/orders/'.$orderId.'/cancel', ['reason' => 'Changed my mind'])
            ->assertOk();
        $cancelled->assertJsonPath('data.status', 'cancelled');
        $cancelled->assertJsonPath('data.payment_method', 'cash_on_pickup');
        $cancelled->assertJsonPath('data.payment_status', 'unpaid');
        $cancelled->assertJsonPath('data.estimate_total_paisa', 1500);
        $cancelled->assertJsonPath('data.items.0.generic_name', 'Pickupjourney');
        $cancelled->assertJsonPath('data.items.0.unit_price_paisa', 1500);
        $cancelled->assertJsonPath('data.timeline.0.event', 'submitted');
        $cancelled->assertJsonPath('data.timeline.0.status', 'pending_pharmacy_review');
        $cancelled->assertJsonPath('data.timeline.1.event', 'cancelled');
        $cancelled->assertJsonPath('data.timeline.1.status', 'cancelled');
        $cancelled->assertJsonPath('data.timeline.1.reason', 'Changed my mind');

        $events = DB::table('order_status_events')->orderBy('created_at')->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertSame('submitted', $events[0]->event);
        $this->assertSame('pending_pharmacy_review', $events[0]->status);
        $this->assertSame('cancelled', $events[1]->event);
        $this->assertSame('cancelled', $events[1]->status);
        $stored = DB::table('orders')->where('public_id', $orderId)->first();
        $this->assertNotNull($stored);
        $this->assertSame('cash_on_pickup', $stored->payment_method);
        $this->assertSame('unpaid', $stored->payment_status);
        $this->assertSame(1500, (int) $stored->estimate_total_paisa);
        $this->assertSame('Counter pickup', $stored->customer_note);
        $this->assertSame('Pickupjourney', DB::table('order_items')->where('order_id', $stored->id)->value('generic_name'));
        $after = DB::table('pharmacy_inventory')->where('medicine_id', $medicineId)->first();
        $this->assertNotNull($after);
        $this->assertSame($quantityBefore, (int) $after->quantity_on_hand);
        $this->assertSame($versionBefore, (int) $after->version);
    }

    private function assertServerRequestId(TestResponse $response): string
    {
        $requestId = (string) $response->json('meta.request_id');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $requestId,
        );
        $this->assertNotSame('spoofed-client-id', $requestId);
        $this->assertSame($requestId, $response->headers->get('X-Request-Id'));

        return $requestId;
    }

    /**
     * @param  list<string>  $medicineIds
     */
    private function availability(string $token, array $medicineIds, string $addressId): TestResponse
    {
        return $this->withCustomerToken($token)
            ->withHeader('X-Request-Id', 'spoofed-client-id')
            ->getJson('/api/v1/availability?'.http_build_query([
                'medicine_ids' => $medicineIds,
                'address_id' => $addressId,
            ]));
    }

    private function issueToken(string $phone): string
    {
        $e164 = app(PakistaniMobileNormalizer::class)->normalize($phone);
        app(OtpRedis::class)->clearCooldown(app(OtpHasher::class)->phone($e164));
        $requested = $this->postJson('/api/v1/auth/otp/request', ['phone_number' => $phone])->assertStatus(202);
        $challengeId = (string) $requested->json('data.challenge_id');
        $verified = $this->postJson('/api/v1/auth/otp/verify', [
            'phone_number' => $phone,
            'challenge_id' => $challengeId,
            'otp' => (string) app(InMemoryOtpProvider::class)->codeFor($challengeId),
        ])->assertOk();
        $token = (string) $verified->json('data.access_token');
        $this->assertNotSame('', $token);
        $this->assertSame('customer', $verified->json('data.customer.role'));

        return $token;
    }

    private function eligiblePharmacy(PharmacyWorkflow $workflow, User $admin, string $name, string $latitude, string $longitude, string $phone): Pharmacy
    {
        $pharmacy = $workflow->create($admin, [
            'name' => $name,
            'address_text' => $name.' Susan Road',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'license_number' => 'LIC-'.Str::upper(Str::random(8)),
            'phone_e164' => $phone,
        ]);
        $workflow->verifyLicense($admin, $pharmacy, 'Reviewed license file '.$name);
        $workflow->approveLocation($admin, $pharmacy, 'Reviewed map pin');
        $workflow->approve($admin, $pharmacy->refresh());

        return $pharmacy->refresh();
    }

    private function stock(User $admin, Pharmacy $pharmacy, mixed $medicine, bool $pickup): void
    {
        app(InventoryBook::class)->save($admin, $pharmacy, [
            'medicine_id' => (string) $medicine->id,
            'stock_status' => 'in_stock',
            'quantity_on_hand' => 4,
            'unit_price_paisa' => 1500,
            'is_available_for_pickup' => $pickup,
        ]);
    }
}
