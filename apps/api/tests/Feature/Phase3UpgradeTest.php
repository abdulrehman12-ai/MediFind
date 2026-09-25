<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('phase3-upgrade')]
class Phase3UpgradeTest extends TestCase
{
    private const PHASE2 = [
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
    ];

    private const INDEXES = [
        'medicines_active_generic_name_index',
        'medicines_active_brand_name_index',
        'pharmacy_inventory_pickup_discovery_index',
        'pharmacies_eligible_location_gist',
    ];

    protected function tearDown(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true, '--seed' => true]);

        parent::tearDown();
    }

    public function test_phase_3_indexes_upgrade_a_phase_2_database_without_data_loss(): void
    {
        $this->assertSame(0, Artisan::call('db:wipe', ['--force' => true, '--database' => 'pgsql']));

        foreach (self::PHASE2 as $path) {
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--path' => $path]));
        }

        $userId = DB::table('users')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'email' => 'phase3-upgrade@example.test',
            'role' => 'customer',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertFalse($this->indexesPresent());

        $this->assertSame(0, Artisan::call('migrate', [
            '--force' => true,
            '--path' => 'database/migrations/2026_09_25_130000_add_discovery_indexes.php',
        ]));

        $this->assertTrue($this->indexesPresent());
        $this->assertTrue(Schema::hasTable('pharmacies'));
        $this->assertSame('phase3-upgrade@example.test', DB::table('users')->where('id', $userId)->value('email'));

        $this->assertSame(0, Artisan::call('migrate:rollback', ['--force' => true, '--step' => 1]));

        $this->assertFalse($this->indexesPresent());
        $this->assertTrue(Schema::hasTable('pharmacies'));
        $this->assertSame('phase3-upgrade@example.test', DB::table('users')->where('id', $userId)->value('email'));
    }

    private function indexesPresent(): bool
    {
        $found = DB::table('pg_indexes')
            ->whereIn('indexname', self::INDEXES)
            ->pluck('indexname')
            ->all();

        return count($found) === count(self::INDEXES);
    }
}
