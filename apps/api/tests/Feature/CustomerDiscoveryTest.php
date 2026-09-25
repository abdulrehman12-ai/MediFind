<?php

namespace Tests\Feature;

use App\Catalog\CatalogText;
use App\Catalog\MedicineCatalog;
use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Identity\AddressBook;
use App\Inventory\InventoryBook;
use App\Models\City;
use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Models\User;
use App\Pharmacy\OperatingHoursWriter;
use App\Pharmacy\PharmacyWorkflow;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CustomerDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CitySeeder::class);
        $this->admin = $this->user(UserRole::Admin, 'admin-discovery@example.test', null);
        $this->customer = $this->user(UserRole::Customer, null, '+923001110001');
        $this->token = $this->customer->createToken('customer', ['customer:access'], now()->addDay())->plainTextToken;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_search_requires_a_customer_and_ranks_exact_variants_without_substitution(): void
    {
        $this->getJson('/api/v1/medicines?q=panadol')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');

        $staff = $this->user(UserRole::PharmacyStaff, 'staff-discovery@example.test', null);
        $staffToken = $staff->createToken('customer', ['customer:access'], now()->addDay())->plainTextToken;
        $this->withCustomerToken($staffToken)->getJson('/api/v1/medicines?q=panadol')->assertForbidden()->assertJsonPath('error.code', 'forbidden');

        $otherAbility = $this->customer->createToken('other', ['pharmacy:access'], now()->addDay())->plainTextToken;
        $this->withCustomerToken($otherAbility)->getJson('/api/v1/medicines?q=panadol')->assertForbidden();

        $exact = $this->medicine('Paracetamol', 'Tablet', '500 mg', 'Alpha Labs', 'Panadol');
        $prefix = $this->medicine('Analgesic', 'Tablet', '500 mg', 'Alpha Labs', 'Panadol Extra');
        $contained = $this->medicine('Cold Relief', 'Tablet', '500 mg', 'Alpha Labs', 'Super Panadol Plus');
        $otherStrength = $this->medicine('Paracetamol', 'Tablet', '250 mg', 'Alpha Labs', null);
        $otherMaker = $this->medicine('Paracetamol', 'Tablet', '500 mg', 'Beta Labs', null);
        $inactive = $this->medicine('Panadol Inactive', 'Tablet', '500 mg', 'Alpha Labs', null, false);
        $shared = $this->medicine('Ibuprofen', 'Tablet', '200 mg', 'Beta Labs', null);
        app(MedicineCatalog::class)->addAlias($this->admin, $exact, 'Pain Relief', 'local');
        app(MedicineCatalog::class)->addAlias($this->admin, $shared, 'Pain Relief', 'local');
        app(MedicineCatalog::class)->draftUse($this->admin, $exact, 'SECRET_USE_TEXT', 'Do not publish', 'Draft source');

        $ranked = $this->withCustomerToken($this->token)->getJson('/api/v1/medicines?q=panadol');
        $ranked->assertOk()
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonMissingPath('data.0.controlled_drug');
        $ids = array_column($ranked->json('data'), 'id');
        $this->assertSame([(string) $exact->id, (string) $prefix->id, (string) $contained->id], $ids);
        $this->assertNotContains((string) $inactive->id, $ids);
        $this->assertStringNotContainsString('SECRET_USE_TEXT', $ranked->getContent());

        $variants = $this->withCustomerToken($this->token)->getJson('/api/v1/medicines?q=paracetamol')->json('data');
        $strengths = array_column($variants, 'strength');
        $this->assertEqualsCanonicalizing(['500 mg', '250 mg', '500 mg'], $strengths);
        $this->assertContains((string) $otherStrength->id, array_column($variants, 'id'));
        $this->assertContains((string) $otherMaker->id, array_column($variants, 'id'));

        $aliases = $this->withCustomerToken($this->token)->getJson('/api/v1/medicines?q=pain%20relief')->json('data');
        $this->assertEqualsCanonicalizing([(string) $exact->id, (string) $shared->id], array_column($aliases, 'id'));

        $wildcard = $this->medicine('A%B Match', 'Syrup', '5 ml', 'Gamma Labs', null);
        $plain = $this->medicine('AB Match', 'Syrup', '5 ml', 'Gamma Labs', null);
        $escaped = $this->withCustomerToken($this->token)->getJson('/api/v1/medicines?'.http_build_query(['q' => 'a%']));
        $escaped->assertOk();
        $escapedIds = array_column($escaped->json('data'), 'id');
        $this->assertSame([(string) $wildcard->id], $escapedIds);
        $this->assertNotContains((string) $plain->id, $escapedIds);

        $this->withCustomerToken($this->token)->getJson('/api/v1/medicines?q=a')->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->withCustomerToken($this->token)->getJson('/api/v1/medicines?q=panadol&per_page=51')->assertStatus(422);
        $this->withCustomerToken($this->token)->getJson('/api/v1/medicines?q=panadol&page=26')->assertStatus(422);
        $this->withCustomerToken($this->token)->getJson('/api/v1/medicines?q=panadol&sort=name')->assertStatus(422);

        $controlled = $this->medicine('Diazepam', 'Tablet', '5 mg', 'Alpha Labs', null, true, true);
        $ordinary = $this->medicine('Diazepam', 'Tablet', '2 mg', 'Alpha Labs', null);
        $diazepam = array_column($this->withCustomerToken($this->token)->getJson('/api/v1/medicines?q=diazepam')->json('data'), 'id');
        $this->assertSame([(string) $ordinary->id], $diazepam);
        $this->assertNotContains((string) $controlled->id, $diazepam);
    }

    public function test_search_length_uses_the_normalized_text(): void
    {
        $expanded = str_repeat("\u{FB01}", 41);
        $this->assertLessThanOrEqual(80, mb_strlen($expanded));
        $this->assertGreaterThan(80, mb_strlen(CatalogText::normalize($expanded)));

        $this->withCustomerToken($this->token)
            ->getJson('/api/v1/medicines?'.http_build_query(['q' => $expanded]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');

        $this->withCustomerToken($this->token)
            ->getJson('/api/v1/medicines?q='.str_repeat('a', 80))
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->withCustomerToken($this->token)
            ->getJson('/api/v1/medicines?q='.str_repeat('a', 81))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_medicine_uuid_case_is_canonical_and_duplicates_fail_validation(): void
    {
        $pharmacy = $this->eligiblePharmacy('Case Pharmacy', '31.450400', '73.135000');
        $medicine = $this->medicine('Loratadine', 'Tablet', '10 mg', 'Alpha Labs', null);
        $this->stock($pharmacy, $medicine, 'in_stock', 3, 900, true);
        $canonical = strtolower((string) $medicine->id);

        $this->availability([
            'medicine_ids' => [strtoupper($canonical)],
            'latitude' => '31.450000',
            'longitude' => '73.135000',
        ])->assertOk()->assertJsonPath('data.0.medicines.0.id', $canonical);

        $this->availability([
            'medicine_ids' => [strtoupper($canonical), $canonical],
            'latitude' => '31.450000',
            'longitude' => '73.135000',
        ])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
    }

    public function test_availability_returns_only_fully_matched_nearby_pharmacies(): void
    {
        $this->getJson('/api/v1/availability?medicine_ids[]='.Str::uuid())->assertUnauthorized();

        $originLat = '31.450000';
        $originLng = '73.135000';
        $near = $this->eligiblePharmacy('Near Pharmacy', '31.450400', $originLng);
        $mid = $this->eligiblePharmacy('Mid Pharmacy', '31.470000', $originLng);
        $same = $this->eligiblePharmacy('Same Point Pharmacy', '31.451000', $originLng);
        $far = $this->eligiblePharmacy('Far Pharmacy', '31.700000', $originLng);
        $suspended = $this->eligiblePharmacy('Suspended Pharmacy', '31.451200', $originLng);
        app(PharmacyWorkflow::class)->suspend($this->admin, $suspended->refresh(), 'Closed by review');
        $rejected = $this->pharmacy('Rejected Pharmacy', '31.451300', $originLng, false);
        app(PharmacyWorkflow::class)->reject($this->admin, $rejected, 'Incomplete file');
        $unverified = $this->pharmacy('Unverified Pharmacy', '31.451400', $originLng, true);
        app(PharmacyWorkflow::class)->approveLocation($this->admin, $unverified, 'Map pin reviewed');
        app(PharmacyWorkflow::class)->approve($this->admin, $unverified->refresh());
        $pendingLocation = $this->pharmacy('Pending Location', '31.451500', $originLng, true);
        app(PharmacyWorkflow::class)->verifyLicense($this->admin, $pendingLocation, 'License file');
        app(PharmacyWorkflow::class)->approve($this->admin, $pendingLocation->refresh());

        $first = $this->medicine('Amoxicillin', 'Capsule', '250 mg', 'Alpha Labs', null);
        $second = $this->medicine('Cetirizine', 'Tablet', '10 mg', 'Alpha Labs', null);
        app(MedicineCatalog::class)->draftUse($this->admin, $first, 'SECRET_USE_TEXT', 'Do not publish', 'Draft source');
        $this->stock($near, $first, 'in_stock', null, 2500, true);
        $this->stock($near, $second, 'low_stock', 2, 1800, true);
        $this->stock($mid, $first, 'in_stock', 3, 2600, true);
        $this->stock($mid, $second, 'in_stock', 3, 1900, true);
        $this->stock($same, $first, 'in_stock', 1, 2400, true);
        $this->stock($same, $second, 'in_stock', 1, 1700, true);
        $this->stock($far, $first, 'in_stock', 8, 3000, true);
        $this->stock($far, $second, 'in_stock', 8, 3000, true);
        $this->stock($suspended, $first, 'in_stock', 4, 2500, true);
        $this->stock($suspended, $second, 'in_stock', 4, 2500, true);

        $both = $this->availability([
            'medicine_ids' => [(string) $first->id, (string) $second->id],
            'latitude' => $originLat,
            'longitude' => $originLng,
        ]);
        $both->assertOk()
            ->assertJsonPath('meta.radius_meters', 10000)
            ->assertJsonPath('meta.freshness_hours', 24)
            ->assertJsonPath('meta.distance_method', 'straight_line')
            ->assertJsonPath('meta.confirmation', 'Stock and price require pharmacy confirmation and are not a reservation.')
            ->assertJsonPath('data.0.distance_method', 'straight_line')
            ->assertJsonPath('data.0.id', (string) $near->id);
        $nearRow = collect($both->json('data'))->firstWhere('id', (string) $near->id);
        $this->assertIsArray($nearRow);
        $low = collect($nearRow['medicines'])->firstWhere('id', (string) $second->id);
        $this->assertIsArray($low);
        $this->assertSame('low_stock', $low['stock_status']);
        $this->assertSame(1800, $low['unit_price_paisa']);
        $returnedIds = array_column($nearRow['medicines'], 'id');
        $expectedIds = [(string) $first->id, (string) $second->id];
        sort($returnedIds);
        sort($expectedIds);
        $this->assertSame($expectedIds, $returnedIds);
        foreach ($nearRow['medicines'] as $summary) {
            $this->assertContains($summary['stock_status'], ['in_stock', 'low_stock']);
        }
        $ids = array_column($both->json('data'), 'id');
        $this->assertSame((string) $same->id, $ids[1]);
        $this->assertSame((string) $mid->id, $ids[2]);
        $this->assertNotContains((string) $far->id, $ids);
        $this->assertNotContains((string) $suspended->id, $ids);
        $this->assertNotContains((string) $rejected->id, $ids);
        $this->assertNotContains((string) $unverified->id, $ids);
        $this->assertNotContains((string) $pendingLocation->id, $ids);
        $this->assertSame('31.450400', $both->json('data.0.latitude'));
        $this->assertSame($originLng, $both->json('data.0.longitude'));
        $body = $both->getContent();
        $this->assertStringNotContainsString('SECRET_USE_TEXT', $body);
        $this->assertStringNotContainsString('License file', $body);
        $this->assertStringNotContainsString('quantity_on_hand', $body);
        $this->assertStringNotContainsString('phone_e164', $body);

        $one = $this->availability([
            'medicine_ids' => [(string) $first->id],
            'latitude' => $originLat,
            'longitude' => $originLng,
            'per_page' => 1,
        ]);
        $one->assertOk()->assertJsonPath('meta.per_page', 1)->assertJsonPath('meta.has_more', true)->assertJsonCount(1, 'data');
        $pageTwo = $this->availability([
            'medicine_ids' => [(string) $first->id],
            'latitude' => $originLat,
            'longitude' => $originLng,
            'per_page' => 1,
            'page' => 2,
        ]);
        $this->assertNotSame($one->json('data.0.id'), $pageTwo->json('data.0.id'));

        $onlyFirst = $this->eligiblePharmacy('Partial Pharmacy', '31.452000', $originLng);
        $this->stock($onlyFirst, $first, 'in_stock', 5, 2000, true);
        $partial = $this->availability([
            'medicine_ids' => [(string) $first->id, (string) $second->id],
            'latitude' => $originLat,
            'longitude' => $originLng,
            'per_page' => 50,
        ]);
        $this->assertNotContains((string) $onlyFirst->id, array_column($partial->json('data'), 'id'));

        $address = app(AddressBook::class)->create($this->customer, [
            'label' => 'Home',
            'address_text' => 'Susan Road',
            'latitude' => $originLat,
            'longitude' => $originLng,
        ]);
        $this->availability([
            'medicine_ids' => [(string) $first->id],
            'address_id' => $address->public_id,
        ])->assertOk()->assertJsonPath('data.0.latitude', '31.450400');

        $other = $this->user(UserRole::Customer, null, '+923001110002');
        $foreign = app(AddressBook::class)->create($other, [
            'label' => 'Other',
            'address_text' => 'Other Road',
            'latitude' => $originLat,
            'longitude' => $originLng,
        ]);
        $this->availability([
            'medicine_ids' => [(string) $first->id],
            'address_id' => $foreign->public_id,
        ])->assertNotFound()->assertJsonPath('error.code', 'not_found');

        $this->availability([
            'medicine_ids' => [(string) $first->id],
            'address_id' => $address->public_id,
            'latitude' => $originLat,
            'longitude' => $originLng,
        ])->assertStatus(422);
        $this->availability([
            'medicine_ids' => [(string) $first->id],
            'latitude' => '91',
            'longitude' => $originLng,
        ])->assertStatus(422);
        $this->availability([
            'medicine_ids' => [(string) $first->id],
        ])->assertStatus(422);
        $this->availability([
            'medicine_ids' => [(string) $first->id, (string) $first->id],
            'latitude' => $originLat,
            'longitude' => $originLng,
        ])->assertStatus(422);
        $this->availability([
            'medicine_ids' => [(string) $first->id],
            'latitude' => $originLat,
            'longitude' => $originLng,
            'radius_meters' => 50001,
        ])->assertStatus(422);

        $distance = (float) DB::selectOne(
            'select ST_Distance(location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) as meters from pharmacies where id = ?',
            [$originLng, $originLat, $far->id],
        )->meters;
        $this->availability([
            'medicine_ids' => [(string) $first->id],
            'latitude' => $originLat,
            'longitude' => $originLng,
            'radius_meters' => (int) ceil($distance),
            'per_page' => 50,
        ])->assertOk();
        $this->assertContains((string) $far->id, array_column($this->availability([
            'medicine_ids' => [(string) $first->id],
            'latitude' => $originLat,
            'longitude' => $originLng,
            'radius_meters' => (int) ceil($distance),
            'per_page' => 50,
        ])->json('data'), 'id'));
        $inside = max(1, (int) floor($distance) - 1);
        $this->assertNotContains((string) $far->id, array_column($this->availability([
            'medicine_ids' => [(string) $first->id],
            'latitude' => $originLat,
            'longitude' => $originLng,
            'radius_meters' => $inside,
            'per_page' => 50,
        ])->json('data'), 'id'));
    }

    public function test_ineligible_medicines_and_inventory_do_not_leak_or_match_partially(): void
    {
        $pharmacy = $this->eligiblePharmacy('Stock Pharmacy', '31.451000', '73.135000');
        $active = $this->medicine('Losartan', 'Tablet', '50 mg', 'Alpha Labs', null);
        $this->stock($pharmacy, $active, 'in_stock', 6, 4200, true);
        $controlled = $this->medicine('Diazepam', 'Tablet', '5 mg', 'Alpha Labs', null, true, true);
        $inactive = $this->medicine('Retired', 'Tablet', '10 mg', 'Alpha Labs', null, false);
        $missing = (string) Str::uuid();

        $controlledResponse = $this->availability([
            'medicine_ids' => [(string) $active->id, (string) $controlled->id],
            'latitude' => '31.450000',
            'longitude' => '73.135000',
        ]);
        $missingResponse = $this->availability([
            'medicine_ids' => [(string) $active->id, $missing],
            'latitude' => '31.450000',
            'longitude' => '73.135000',
        ]);
        $inactiveResponse = $this->availability([
            'medicine_ids' => [(string) $inactive->id],
            'latitude' => '31.450000',
            'longitude' => '73.135000',
        ]);
        $controlledResponse->assertStatus(422)->assertJsonPath('error.code', 'medicine_unavailable');
        $this->assertSame($controlledResponse->json('error'), $missingResponse->json('error'));
        $this->assertSame($controlledResponse->json('error.message'), $inactiveResponse->json('error.message'));
        $this->assertStringNotContainsString((string) $controlled->id, $controlledResponse->getContent());
        $this->assertStringNotContainsString($missing, $missingResponse->getContent());

        $cleared = $this->eligiblePharmacy('Cleared Coordinates', '31.451100', '73.135000');
        $this->stock($cleared, $active, 'in_stock', 2, 1000, true);
        DB::table('pharmacies')->where('id', $cleared->id)->update(['latitude' => null, 'longitude' => null]);
        $this->assertNotContains((string) $cleared->id, $this->currentIds($active));
        City::query()->update(['is_active' => false]);
        $this->availability([
            'medicine_ids' => [(string) $active->id],
            'latitude' => '31.450000',
            'longitude' => '73.135000',
        ])->assertOk()->assertJsonCount(0, 'data');
        City::query()->update(['is_active' => true, 'name' => 'Lahore']);
        $this->availability([
            'medicine_ids' => [(string) $active->id],
            'latitude' => '31.450000',
            'longitude' => '73.135000',
        ])->assertOk()->assertJsonCount(0, 'data');
        City::query()->update(['name' => 'Faisalabad']);

        $states = [
            ['unknown', null, false],
            ['out_of_stock', 0, false],
            ['in_stock', 4, false],
        ];
        foreach ($states as [$status, $quantity, $pickup]) {
            $row = $this->eligiblePharmacy('State '.$status, '31.452000', '73.135000');
            $this->stock($row, $active, $status, $quantity, 1000, $pickup);
            $this->assertNotContains((string) $row->id, $this->currentIds($active));
        }

        Carbon::setTestNow(Carbon::parse('2026-09-25 12:00:00', 'UTC'));
        $fresh = $this->eligiblePharmacy('Freshness Pharmacy', '31.453000', '73.135000');
        $this->stock($fresh, $active, 'in_stock', 4, 1000, true);
        DB::table('pharmacy_inventory')->where('pharmacy_id', $fresh->id)->update([
            'last_verified_at' => now()->subHours(24),
        ]);
        $this->assertContains((string) $fresh->id, $this->currentIds($active));
        DB::table('pharmacy_inventory')->where('pharmacy_id', $fresh->id)->update([
            'last_verified_at' => now()->subHours(24)->subSecond(),
        ]);
        $this->assertNotContains((string) $fresh->id, $this->currentIds($active));
        DB::table('pharmacy_inventory')->where('pharmacy_id', $fresh->id)->update([
            'last_verified_at' => now()->addMinute(),
        ]);
        $this->assertNotContains((string) $fresh->id, $this->currentIds($active));
    }

    public function test_open_now_follows_karachi_hours_and_missing_hours_stay_unknown(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-21 05:00:00', 'Asia/Karachi'));
        $pharmacy = $this->eligiblePharmacy('Hours Pharmacy', '31.451000', '73.135000');
        $medicine = $this->medicine('Vitamin C', 'Tablet', '500 mg', 'Alpha Labs', null);
        $this->stock($pharmacy, $medicine, 'in_stock', 9, 500, true);
        $query = [
            'medicine_ids' => [(string) $medicine->id],
            'latitude' => '31.450000',
            'longitude' => '73.135000',
        ];

        $this->availability($query)->assertOk()->assertJsonPath('data.0.open_now', null);

        app(OperatingHoursWriter::class)->replace($this->admin, $pharmacy, [
            ['day_of_week' => 7, 'interval_kind' => 'overnight', 'opens_at' => '22:00', 'closes_at' => '06:00'],
        ]);
        $this->availability($query)->assertJsonPath('data.0.open_now', true);

        Carbon::setTestNow(Carbon::parse('2026-09-21 07:00:00', 'Asia/Karachi'));
        $this->availability($query)->assertJsonPath('data.0.open_now', false);

        app(OperatingHoursWriter::class)->replace($this->admin, $pharmacy, [
            ['day_of_week' => 1, 'interval_kind' => 'same_day', 'opens_at' => '09:00', 'closes_at' => '13:00'],
            ['day_of_week' => 1, 'interval_kind' => 'same_day', 'opens_at' => '16:00', 'closes_at' => '20:00'],
        ]);
        Carbon::setTestNow(Carbon::parse('2026-09-21 10:00:00', 'Asia/Karachi'));
        $this->availability($query)->assertJsonPath('data.0.open_now', true);
        Carbon::setTestNow(Carbon::parse('2026-09-21 13:00:00', 'Asia/Karachi'));
        $this->availability($query)->assertJsonPath('data.0.open_now', false);
        Carbon::setTestNow(Carbon::parse('2026-09-21 17:00:00', 'Asia/Karachi'));
        $this->availability($query)->assertJsonPath('data.0.open_now', true);

        app(OperatingHoursWriter::class)->replace($this->admin, $pharmacy, [
            ['day_of_week' => 2, 'interval_kind' => 'all_day', 'opens_at' => null, 'closes_at' => null],
        ]);
        Carbon::setTestNow(Carbon::parse('2026-09-22 03:00:00', 'Asia/Karachi'));
        $this->availability($query)->assertJsonPath('data.0.open_now', true);
        Carbon::setTestNow(Carbon::parse('2026-09-21 12:00:00', 'Asia/Karachi'));
        $this->availability($query)->assertJsonPath('data.0.open_now', false);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function availability(array $query): TestResponse
    {
        return $this->withCustomerToken($this->token)->getJson('/api/v1/availability?'.http_build_query($query));
    }

    /**
     * @return list<string>
     */
    private function currentIds(Medicine $medicine): array
    {
        return array_column($this->availability([
            'medicine_ids' => [(string) $medicine->id],
            'latitude' => '31.450000',
            'longitude' => '73.135000',
            'per_page' => 50,
        ])->json('data'), 'id');
    }

    private function medicine(string $generic, string $form, string $strength, string $manufacturer, ?string $brand, bool $activate = true, bool $controlled = false): Medicine
    {
        $catalog = app(MedicineCatalog::class);
        $medicine = $catalog->create($this->admin, [
            'generic_name' => $generic,
            'dosage_form' => $form,
            'strength' => $strength,
            'manufacturer' => $manufacturer,
            'brand_name' => $brand,
            'controlled_drug' => $controlled,
        ]);

        if ($activate) {
            $catalog->activate($this->admin, $medicine);
        }

        return $medicine->refresh();
    }

    private function eligiblePharmacy(string $name, string $latitude, string $longitude): Pharmacy
    {
        $pharmacy = $this->pharmacy($name, $latitude, $longitude, true);
        $workflow = app(PharmacyWorkflow::class);
        $workflow->verifyLicense($this->admin, $pharmacy, 'Reviewed license file '.$name);
        $workflow->approveLocation($this->admin, $pharmacy, 'Reviewed map pin');
        $workflow->approve($this->admin, $pharmacy->refresh());

        return $pharmacy->refresh();
    }

    private function pharmacy(string $name, string $latitude, string $longitude, bool $licensed): Pharmacy
    {
        return app(PharmacyWorkflow::class)->create($this->admin, [
            'name' => $name,
            'address_text' => $name.' Susan Road',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'license_number' => $licensed ? 'LIC-'.Str::upper(Str::random(8)) : null,
            'phone_e164' => '+92300999'.str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT),
        ]);
    }

    private function stock(Pharmacy $pharmacy, Medicine $medicine, string $status, ?int $quantity, int $price, bool $pickup): void
    {
        app(InventoryBook::class)->save($this->admin, $pharmacy, [
            'medicine_id' => (string) $medicine->id,
            'stock_status' => $status,
            'quantity_on_hand' => $quantity,
            'unit_price_paisa' => $price,
            'is_available_for_pickup' => $pickup,
        ]);
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
}
