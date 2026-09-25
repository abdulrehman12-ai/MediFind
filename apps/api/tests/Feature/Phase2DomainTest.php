<?php

namespace Tests\Feature;

use App\Catalog\MedicineCatalog;
use App\Enums\AccountStatus;
use App\Enums\CredentialStatus;
use App\Enums\LicenseStatus;
use App\Enums\LocationReviewStatus;
use App\Enums\PharmacyStatus;
use App\Enums\StaffRole;
use App\Enums\UserRole;
use App\Inventory\InvalidInventory;
use App\Inventory\InventoryBook;
use App\Inventory\InventoryConflict;
use App\Models\MedicineUse;
use App\Models\Pharmacy;
use App\Models\PharmacyInventory;
use App\Models\PharmacyMembership;
use App\Models\User;
use App\Pharmacy\InvalidOperatingHours;
use App\Pharmacy\OperatingHoursWriter;
use App\Pharmacy\PharmacyRuleException;
use App\Pharmacy\PharmacyWorkflow;
use Database\Seeders\CitySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase2DomainTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CitySeeder::class);
        $this->admin = $this->user(UserRole::Admin, 'admin-phase2@example.test');
    }

    public function test_pharmacy_review_records_evidence_and_public_eligibility_is_a_conjunction(): void
    {
        $pharmacy = app(PharmacyWorkflow::class)->create($this->admin, [
            'name' => 'Clock Pharmacy',
            'address_text' => 'Susan Road',
            'license_number' => 'DRAP-LOCAL-1',
            'latitude' => '31.418000',
            'longitude' => '73.079000',
        ]);

        $this->assertFalse($pharmacy->publiclyEligible());
        app(PharmacyWorkflow::class)->approve($this->admin, $pharmacy);
        $pharmacy->refresh();
        $this->assertSame(PharmacyStatus::Approved, $pharmacy->status);
        $this->assertFalse($pharmacy->publiclyEligible());

        app(PharmacyWorkflow::class)->verifyLicense($this->admin, $pharmacy, 'File DRAP-LOCAL-1 inspected on paper.');
        app(PharmacyWorkflow::class)->approveLocation($this->admin, $pharmacy, 'Coordinates checked against the shopfront.');
        $pharmacy->refresh();

        $this->assertTrue($pharmacy->publiclyEligible());
        $this->assertSame($this->admin->id, $pharmacy->license_reviewed_by);
        $this->assertSame('documented_admin_review', $pharmacy->license_verification_method);
        $this->assertNotNull($pharmacy->license_reviewed_at);
        $this->assertNotSame('', trim((string) $pharmacy->license_evidence));

        app(PharmacyWorkflow::class)->updateProfile($this->admin, $pharmacy, ['license_number' => 'DRAP-LOCAL-2']);
        $pharmacy->refresh();
        $this->assertSame(LicenseStatus::Unverified, $pharmacy->license_status);
        $this->assertFalse($pharmacy->publiclyEligible());
    }

    public function test_rejected_pharmacy_can_appeal_and_suspension_cuts_off_sessions(): void
    {
        $pharmacy = $this->pharmacy();
        $manager = $this->user(UserRole::PharmacyStaff, 'manager-phase2@example.test');
        $membership = $this->membership($pharmacy, $manager, StaffRole::Manager);
        $this->assertSame(0, $manager->refresh()->session_generation);

        app(PharmacyWorkflow::class)->reject($this->admin, $pharmacy, 'Address could not be confirmed.');
        app(PharmacyWorkflow::class)->appeal($manager, $pharmacy->refresh(), 'Corrected shop number is 14.');
        $this->assertSame(PharmacyStatus::Pending, $pharmacy->refresh()->status);
        $this->assertSame(0, $manager->refresh()->session_generation);

        app(PharmacyWorkflow::class)->approve($this->admin, $pharmacy->refresh());
        app(PharmacyWorkflow::class)->suspend($this->admin, $pharmacy->refresh(), 'Pending a new inspection.');
        $this->assertSame(1, $manager->refresh()->session_generation);
        $this->assertSame(CredentialStatus::Unverified, $membership->refresh()->credential_status);

        $this->expectException(PharmacyRuleException::class);
        app(InventoryBook::class)->save($manager, $pharmacy->refresh(), $this->stock());
    }

    public function test_credential_verification_stores_the_reviewer_and_is_not_the_role_string(): void
    {
        $pharmacy = $this->pharmacy();
        $pharmacist = $this->user(UserRole::PharmacyStaff, 'pharmacist-phase2@example.test');
        $membership = $this->membership($pharmacy, $pharmacist, StaffRole::Pharmacist);
        $membership->forceFill(['license_number' => 'RPH-100'])->save();
        $membership->refresh();

        $this->assertSame(CredentialStatus::Unverified, $membership->credential_status);
        app(PharmacyWorkflow::class)->verifyCredential($this->admin, $membership, 'Category A registration reviewed from the scanned certificate.');
        $membership->refresh();

        $this->assertSame(CredentialStatus::Verified, $membership->credential_status);
        $this->assertSame(StaffRole::Pharmacist, $membership->staff_role);
        $this->assertSame($this->admin->id, $membership->credential_reviewed_by);
        $this->assertSame('documented_admin_review', $membership->credential_verification_method);
        $this->assertNotNull($membership->credential_reviewed_at);
    }

    public function test_incomplete_medicine_stays_inactive_and_aliases_are_not_globally_unique(): void
    {
        $catalog = app(MedicineCatalog::class);
        $incomplete = $catalog->create($this->admin, [
            'generic_name' => 'Ibuprofen',
            'dosage_form' => 'Tablet',
            'strength' => '400 mg',
        ]);
        $this->assertFalse($incomplete->identityIsComplete());

        try {
            $catalog->activate($this->admin, $incomplete);
            $this->fail('An incomplete medicine was activated.');
        } catch (PharmacyRuleException) {
        }

        $first = $catalog->create($this->admin, [
            'generic_name' => 'Paracetamol',
            'dosage_form' => 'Tablet',
            'strength' => '500 mg',
            'manufacturer' => 'Alpha Labs',
        ]);
        $second = $catalog->create($this->admin, [
            'generic_name' => 'Paracetamol',
            'dosage_form' => 'Tablet',
            'strength' => '500 mg',
            'manufacturer' => 'Beta Labs',
        ]);
        $catalog->activate($this->admin, $first);
        $catalog->activate($this->admin, $second);
        $catalog->addAlias($this->admin, $first, 'Panadol', 'brand');
        $catalog->addAlias($this->admin, $second, 'Panadol', 'brand');

        $this->assertSame(2, DB::table('medicine_aliases')->where('normalized_alias', 'panadol')->count());

        $use = $catalog->draftUse($this->admin, $first, 'Internal draft only.', 'Not medical advice.', 'Example formulary, internal copy.');
        $this->assertSame('draft', $use->review_status);

        try {
            DB::transaction(function () use ($use): void {
                MedicineUse::query()->whereKey($use->id)->update(['review_status' => 'approved']);
            });
            $this->fail('A general-use draft was approved.');
        } catch (QueryException) {
        }

        $this->assertSame('draft', $use->refresh()->review_status);
    }

    public function test_inventory_preserves_utc_verification_and_blocks_controlled_pickup(): void
    {
        $pharmacy = $this->pharmacy();
        $manager = $this->user(UserRole::PharmacyStaff, 'stock-phase2@example.test');
        $this->membership($pharmacy, $manager, StaffRole::Manager);
        $medicine = app(MedicineCatalog::class)->create($this->admin, [
            'generic_name' => 'Amoxicillin',
            'dosage_form' => 'Capsule',
            'strength' => '250 mg',
            'manufacturer' => 'Gamma Labs',
            'controlled_drug' => true,
        ]);
        app(MedicineCatalog::class)->activate($this->admin, $medicine);

        $saved = app(InventoryBook::class)->save($manager, $pharmacy, [
            'medicine_id' => $medicine->id,
            'stock_status' => 'in_stock',
            'quantity_on_hand' => 4,
            'unit_price_paisa' => 15000,
            'is_available_for_pickup' => false,
        ]);
        $this->assertFalse($saved->is_available_for_pickup);
        $this->assertNotNull($saved->last_verified_at);
        $this->assertSame('UTC', $saved->last_verified_at->timezone->getName());

        try {
            app(InventoryBook::class)->save($manager, $pharmacy, [
                'medicine_id' => $medicine->id,
                'stock_status' => 'in_stock',
                'quantity_on_hand' => 4,
                'unit_price_paisa' => 15000,
                'is_available_for_pickup' => true,
                'version' => $saved->version,
            ]);
            $this->fail('Controlled pickup was accepted.');
        } catch (InvalidInventory) {
        }

        try {
            DB::transaction(function () use ($saved): void {
                DB::table('pharmacy_inventory')->where('id', $saved->id)->update(['is_available_for_pickup' => true]);
            });
            $this->fail('The database accepted controlled pickup.');
        } catch (QueryException) {
        }

        $this->assertFalse($saved->refresh()->is_available_for_pickup);
    }

    public function test_stale_inventory_negative_values_and_duplicate_rows_fail(): void
    {
        $pharmacy = $this->pharmacy();
        $other = $this->pharmacy('Second Clock Pharmacy');
        $manager = $this->user(UserRole::PharmacyStaff, 'inventory-phase2@example.test');
        $this->membership($pharmacy, $manager, StaffRole::InventoryStaff);
        $medicine = app(MedicineCatalog::class)->create($this->admin, [
            'generic_name' => 'Cetirizine',
            'dosage_form' => 'Tablet',
            'strength' => '10 mg',
            'manufacturer' => 'Delta Labs',
        ]);
        app(MedicineCatalog::class)->activate($this->admin, $medicine);
        $book = app(InventoryBook::class);
        $row = $book->save($manager, $pharmacy, $this->stock($medicine->id));

        $book->save($manager, $pharmacy, $this->stock($medicine->id, $row->version));
        try {
            $book->save($manager, $pharmacy, $this->stock($medicine->id, 1));
            $this->fail('A stale inventory version was saved.');
        } catch (InventoryConflict) {
        }

        try {
            $book->save($manager, $pharmacy, $this->stock($medicine->id, $row->refresh()->version, -1));
            $this->fail('A negative price was saved.');
        } catch (InvalidInventory) {
        }

        $otherRow = $book->save($this->admin, $other, $this->stock($medicine->id));
        $this->assertFalse($manager->can('view', $otherRow));
        $this->assertFalse($manager->can('update', $otherRow));
        $this->assertFalse($manager->can('delete', $otherRow));

        try {
            DB::transaction(function () use ($pharmacy, $medicine): void {
                DB::table('pharmacy_inventory')->insert([
                    'id' => (string) Str::uuid(),
                    'pharmacy_id' => $pharmacy->id,
                    'medicine_id' => $medicine->id,
                    'stock_status' => 'unknown',
                    'quantity_on_hand' => null,
                    'unit_price_paisa' => 100,
                    'is_available_for_pickup' => false,
                    'version' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
            $this->fail('A duplicate inventory row was stored.');
        } catch (QueryException) {
        }

        $this->assertSame(1, PharmacyInventory::query()->where('pharmacy_id', $pharmacy->id)->where('medicine_id', $medicine->id)->count());
    }

    public function test_database_rejects_invalid_coordinates_license_flags_and_overlapping_hours(): void
    {
        $pharmacy = $this->pharmacy();
        $manager = $this->user(UserRole::PharmacyStaff, 'hours-phase2@example.test');
        $this->membership($pharmacy, $manager, StaffRole::Manager);

        app(OperatingHoursWriter::class)->replace($manager, $pharmacy, [
            ['day_of_week' => 1, 'interval_kind' => 'all_day', 'opens_at' => null, 'closes_at' => null],
            ['day_of_week' => 2, 'interval_kind' => 'same_day', 'opens_at' => '09:00', 'closes_at' => '13:00'],
            ['day_of_week' => 2, 'interval_kind' => 'same_day', 'opens_at' => '16:00', 'closes_at' => '21:00'],
        ]);
        $this->assertSame(3, $pharmacy->operatingHours()->count());

        try {
            app(OperatingHoursWriter::class)->replace($manager, $pharmacy, [
                ['day_of_week' => 5, 'interval_kind' => 'same_day', 'opens_at' => '09:00', 'closes_at' => '18:00'],
                ['day_of_week' => 5, 'interval_kind' => 'same_day', 'opens_at' => '17:00', 'closes_at' => '20:00'],
            ]);
            $this->fail('Overlapping hours were saved.');
        } catch (InvalidOperatingHours) {
        }

        try {
            DB::transaction(function () use ($pharmacy): void {
                DB::table('pharmacies')->where('id', $pharmacy->id)->update(['latitude' => 91]);
            });
            $this->fail('An out-of-range latitude was stored.');
        } catch (QueryException) {
        }

        app(PharmacyWorkflow::class)->approve($this->admin, $pharmacy);

        try {
            DB::transaction(function () use ($pharmacy): void {
                DB::table('pharmacies')->where('id', $pharmacy->id)->update([
                    'license_status' => LicenseStatus::Verified->value,
                ]);
            });
            $this->fail('A license was marked verified without a review record.');
        } catch (QueryException) {
        }

        $this->assertSame(LicenseStatus::Unverified, $pharmacy->refresh()->license_status);
    }

    public function test_location_approval_without_evidence_is_rejected_by_the_database(): void
    {
        $pharmacy = $this->pharmacy();

        try {
            DB::transaction(function () use ($pharmacy): void {
                DB::table('pharmacies')->where('id', $pharmacy->id)->update([
                    'location_review_status' => LocationReviewStatus::Approved->value,
                ]);
            });
            $this->fail('A location was approved without a review record.');
        } catch (QueryException) {
        }

        $this->assertSame(LocationReviewStatus::Pending, $pharmacy->refresh()->location_review_status);
    }

    private function pharmacy(string $name = 'Clock Pharmacy'): Pharmacy
    {
        return app(PharmacyWorkflow::class)->create($this->admin, [
            'name' => $name,
            'address_text' => 'Susan Road',
            'latitude' => '31.418000',
            'longitude' => '73.079000',
        ]);
    }

    private function user(UserRole $role, string $email): User
    {
        return User::query()->forceCreate([
            'public_id' => (string) Str::uuid(),
            'email' => $email,
            'password' => 'not-a-login-secret',
            'role' => $role,
            'status' => AccountStatus::Active,
        ]);
    }

    private function membership(Pharmacy $pharmacy, User $user, StaffRole $role): PharmacyMembership
    {
        return PharmacyMembership::query()->forceCreate([
            'pharmacy_id' => $pharmacy->id,
            'user_id' => $user->id,
            'staff_role' => $role,
            'status' => 'active',
        ]);
    }

    /**
     * @return array{medicine_id: string, stock_status: string, quantity_on_hand: int, unit_price_paisa: int, is_available_for_pickup: bool, version?: int}
     */
    private function stock(?string $medicineId = null, ?int $version = null, int $price = 2500): array
    {
        $payload = [
            'medicine_id' => $medicineId ?? (string) Str::uuid(),
            'stock_status' => 'in_stock',
            'quantity_on_hand' => 3,
            'unit_price_paisa' => $price,
            'is_available_for_pickup' => true,
        ];

        if ($version !== null) {
            $payload['version'] = $version;
        }

        return $payload;
    }
}
