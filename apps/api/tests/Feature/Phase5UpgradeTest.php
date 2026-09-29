<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Throwable;

#[Group('phase5-upgrade')]
class Phase5UpgradeTest extends TestCase
{
    private const BEFORE_PHASE5 = [
        'database/migrations/0001_01_01_000000_create_users_table.php',
        'database/migrations/0001_01_01_000001_create_cache_table.php',
        'database/migrations/0001_01_01_000002_create_jobs_table.php',
        'database/migrations/2026_09_21_000000_create_cities_table.php',
        'database/migrations/2026_09_22_100000_alter_users_for_identity.php',
        'database/migrations/2026_09_22_100100_create_personal_access_tokens.php',
        'database/migrations/2026_09_22_100200_create_user_token_revocation_trigger.php',
        'database/migrations/2026_09_22_100300_create_user_addresses.php',
        'database/migrations/2026_09_22_100400_revoke_tokens_on_customer_role_change.php',
        'database/migrations/2026_09_23_120000_add_panel_authentication_to_users.php',
        'database/migrations/2026_09_23_120100_create_pharmacies.php',
        'database/migrations/2026_09_23_120200_create_pharmacy_operating_hours.php',
        'database/migrations/2026_09_23_120300_create_pharmacy_memberships_and_invitations.php',
        'database/migrations/2026_09_23_120400_create_medicine_catalog.php',
        'database/migrations/2026_09_23_120500_create_pharmacy_inventory_and_audit_events.php',
        'database/migrations/2026_09_25_130000_add_discovery_indexes.php',
        'database/migrations/2026_09_25_180000_create_prescriptions.php',
        'database/migrations/2026_09_25_180100_create_medicine_use_publications.php',
        'database/migrations/2026_09_27_140000_prescription_timestamps_keep_microseconds.php',
    ];

    protected function tearDown(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true, '--seed' => true]);

        parent::tearDown();
    }

    public function test_phase_5_schema_upgrades_a_phase_4_database_and_refuses_to_drop_orders(): void
    {
        $this->assertSame(0, Artisan::call('db:wipe', ['--force' => true, '--database' => 'pgsql']));

        foreach (self::BEFORE_PHASE5 as $path) {
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--path' => $path]));
        }

        $userId = DB::table('users')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'email' => 'phase5-upgrade@example.test',
            'role' => 'customer',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cityId = (string) Str::uuid();
        DB::table('cities')->insert([
            'id' => $cityId,
            'name' => 'Phase5 Upgrade',
            'country_code' => 'PK',
            'timezone' => 'Asia/Karachi',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $pharmacyId = (string) Str::uuid();
        DB::table('pharmacies')->insert([
            'id' => $pharmacyId,
            'city_id' => $cityId,
            'name' => 'Upgrade Pharmacy',
            'address_text' => 'Synthetic road',
            'status' => 'pending',
            'license_status' => 'unverified',
            'location_review_status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $prescriptionId = (string) Str::uuid();
        $prescriptionPublicId = (string) Str::uuid();
        DB::table('prescriptions')->insert([
            'id' => $prescriptionId,
            'public_id' => $prescriptionPublicId,
            'customer_id' => $userId,
            'object_key' => 'rx/phase5-upgrade-synthetic',
            'display_name' => 'synthetic',
            'mime_type' => 'image/jpeg',
            'byte_size' => 128,
            'sha256' => str_repeat('ab', 32),
            'extraction_state' => 'queued',
            'extraction_version' => 1,
            'content_version' => 1,
            'share_state' => 'none',
            'object_state' => 'stored',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertFalse(Schema::hasTable('orders'));

        $phase5 = 'database/migrations/2026_09_27_180000_create_pickup_orders.php';
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--path' => $phase5]));
        $this->assertTrue(Schema::hasTable('orders'));
        $this->assertTrue(Schema::hasTable('order_items'));
        $this->assertTrue(Schema::hasTable('order_status_events'));
        $this->assertTrue(Schema::hasTable('order_submissions'));
        $this->assertTrue(Schema::hasTable('customer_carts'));
        $this->assertSame('phase5-upgrade@example.test', DB::table('users')->where('id', $userId)->value('email'));
        $this->assertSame(1, DB::table('prescriptions')->where('id', $prescriptionId)->count());

        $this->assertSame(0, Artisan::call('migrate:rollback', ['--force' => true, '--step' => 1]));
        $this->assertFalse(Schema::hasTable('orders'));
        $this->assertFalse(Schema::hasTable('customer_carts'));
        $this->assertFalse(Schema::hasTable('customer_cart_sequences'));
        $this->assertTrue(Schema::hasTable('prescriptions'));
        $this->assertSame('phase5-upgrade@example.test', DB::table('users')->where('id', $userId)->value('email'));

        $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--path' => $phase5]));
        DB::table('customer_cart_sequences')->insert([
            'customer_id' => $userId,
            'last_version' => 7,
        ]);
        $this->assertRollbackRefused();
        $this->assertSame(7, (int) DB::table('customer_cart_sequences')->where('customer_id', $userId)->value('last_version'));
        $this->assertTrue(Schema::hasTable('customer_cart_sequences'));
        DB::table('customer_cart_sequences')->where('customer_id', $userId)->delete();

        $cartId = (string) Str::uuid();
        DB::table('customer_carts')->insert([
            'id' => $cartId,
            'customer_id' => $userId,
            'pharmacy_id' => $pharmacyId,
            'request_mode' => 'catalog_items',
            'version' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('customer_cart_sequences')->insert([
            'customer_id' => $userId,
            'last_version' => 3,
        ]);
        $this->assertRollbackRefused();
        $this->assertSame(3, (int) DB::table('customer_carts')->where('id', $cartId)->value('version'));
        $this->assertSame(3, (int) DB::table('customer_cart_sequences')->where('customer_id', $userId)->value('last_version'));
        $this->assertTrue(Schema::hasTable('customer_carts'));
        DB::table('customer_carts')->where('id', $cartId)->delete();
        DB::table('customer_cart_sequences')->where('customer_id', $userId)->delete();

        DB::table('orders')->insert([
            'id' => (string) Str::uuid(),
            'public_id' => (string) Str::uuid(),
            'reference' => 'MFUPGRADE',
            'customer_id' => $userId,
            'pharmacy_id' => $pharmacyId,
            'request_mode' => 'prescription_full_image',
            'prescription_id' => $prescriptionId,
            'prescription_public_id' => $prescriptionPublicId,
            'prescription_content_version' => 1,
            'prescription_share_mode' => 'full_image',
            'prescription_usable' => true,
            'status' => 'pending_pharmacy_review',
            'payment_method' => 'cash_on_pickup',
            'payment_status' => 'unpaid',
            'estimate_total_paisa' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertRollbackRefused();
        $this->assertTrue(Schema::hasTable('orders'));
        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame(1, DB::table('prescriptions')->where('id', $prescriptionId)->count());
        $this->assertSame('phase5-upgrade@example.test', DB::table('users')->where('id', $userId)->value('email'));
    }

    private function assertRollbackRefused(): void
    {
        $refused = false;

        try {
            $code = Artisan::call('migrate:rollback', ['--force' => true, '--step' => 1]);
            $refused = $code !== 0;
        } catch (Throwable $exception) {
            $refused = str_contains($exception->getMessage(), 'Cannot roll back pickup ordering');
        }

        $this->assertTrue($refused);
    }
}
