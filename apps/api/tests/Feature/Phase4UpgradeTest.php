<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('phase4-upgrade')]
class Phase4UpgradeTest extends TestCase
{
    private const BEFORE_PHASE4 = [
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
    ];

    protected function tearDown(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true, '--seed' => true]);

        parent::tearDown();
    }

    public function test_phase_4_schema_upgrades_a_phase_3_database_and_rolls_back_only_phase_4(): void
    {
        $this->assertSame(0, Artisan::call('db:wipe', ['--force' => true, '--database' => 'pgsql']));

        foreach (self::BEFORE_PHASE4 as $path) {
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--path' => $path]));
        }

        $userId = DB::table('users')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'email' => 'phase4-upgrade@example.test',
            'role' => 'customer',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $medicineId = (string) Str::uuid();
        DB::table('medicines')->insert([
            'id' => $medicineId,
            'generic_name' => 'Phase4 Upgrade',
            'dosage_form' => 'Tablet',
            'normalized_generic_name' => 'phase4 upgrade',
            'normalized_dosage_form' => 'tablet',
            'status' => 'inactive',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertFalse(Schema::hasTable('prescriptions'));
        $this->assertTrue(Schema::hasTable('pharmacies'));
        $this->assertTrue(Schema::hasTable('pharmacy_inventory'));
        $this->assertTrue($this->discoveryIndexExists());

        foreach ([
            'database/migrations/2026_09_25_180000_create_prescriptions.php',
            'database/migrations/2026_09_25_180100_create_medicine_use_publications.php',
        ] as $path) {
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--path' => $path]));
        }

        $this->assertTrue(Schema::hasTable('prescriptions'));
        $this->assertTrue(Schema::hasTable('prescription_items'));
        $this->assertTrue(Schema::hasTable('prescription_reviews'));
        $this->assertTrue(Schema::hasTable('prescription_object_holds'));
        $this->assertTrue(Schema::hasTable('prescription_work_cursors'));
        $this->assertTrue(Schema::hasTable('medicine_use_publications'));
        $this->assertSame('phase4-upgrade@example.test', DB::table('users')->where('id', $userId)->value('email'));
        $this->assertSame(1, DB::table('medicines')->where('id', $medicineId)->count());
        $this->assertSame(0, DB::table('pharmacies')->count());
        $this->assertTrue($this->discoveryIndexExists());

        $this->assertSame(0, Artisan::call('migrate:rollback', ['--force' => true, '--step' => 2]));

        $this->assertFalse(Schema::hasTable('prescriptions'));
        $this->assertFalse(Schema::hasTable('prescription_items'));
        $this->assertFalse(Schema::hasTable('prescription_reviews'));
        $this->assertFalse(Schema::hasTable('prescription_object_holds'));
        $this->assertFalse(Schema::hasTable('prescription_work_cursors'));
        $this->assertFalse(Schema::hasTable('medicine_use_publications'));
        $this->assertTrue(Schema::hasTable('medicines'));
        $this->assertTrue(Schema::hasTable('pharmacies'));
        $this->assertTrue(Schema::hasTable('pharmacy_inventory'));
        $this->assertSame('phase4-upgrade@example.test', DB::table('users')->where('id', $userId)->value('email'));
        $this->assertSame(1, DB::table('medicines')->where('id', $medicineId)->count());
        $this->assertTrue($this->discoveryIndexExists());
    }

    private function discoveryIndexExists(): bool
    {
        return DB::table('pg_indexes')->where('indexname', 'pharmacies_eligible_location_gist')->exists();
    }
}
