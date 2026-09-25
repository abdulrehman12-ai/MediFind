<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('phase2-upgrade')]
class Phase2UpgradeTest extends TestCase
{
    private const PHASE1 = [
        'database/migrations/0001_01_01_000000_create_users_table.php',
        'database/migrations/0001_01_01_000001_create_cache_table.php',
        'database/migrations/0001_01_01_000002_create_jobs_table.php',
        'database/migrations/2026_09_21_000000_create_cities_table.php',
        'database/migrations/2026_09_22_100000_alter_users_for_identity.php',
        'database/migrations/2026_09_22_100100_create_personal_access_tokens.php',
        'database/migrations/2026_09_22_100200_create_user_token_revocation_trigger.php',
        'database/migrations/2026_09_22_100300_create_user_addresses.php',
        'database/migrations/2026_09_22_100400_revoke_tokens_on_customer_role_change.php',
    ];

    private const PHASE2 = [
        'database/migrations/2026_09_23_120000_add_panel_authentication_to_users.php',
        'database/migrations/2026_09_23_120100_create_pharmacies.php',
        'database/migrations/2026_09_23_120200_create_pharmacy_operating_hours.php',
        'database/migrations/2026_09_23_120300_create_pharmacy_memberships_and_invitations.php',
        'database/migrations/2026_09_23_120400_create_medicine_catalog.php',
        'database/migrations/2026_09_23_120500_create_pharmacy_inventory_and_audit_events.php',
    ];

    protected function tearDown(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true, '--seed' => true]);

        parent::tearDown();
    }

    public function test_phase_2_upgrades_a_phase_1_database_and_rolls_back(): void
    {
        $this->assertSame(0, Artisan::call('db:wipe', ['--force' => true, '--database' => 'pgsql']));

        foreach (self::PHASE1 as $path) {
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--path' => $path]));
        }

        $userId = DB::table('users')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'email' => 'phase2-upgrade@example.test',
            'role' => 'customer',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertFalse(Schema::hasTable('pharmacies'));

        foreach (self::PHASE2 as $path) {
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--path' => $path]));
        }

        $this->assertTrue(Schema::hasTable('pharmacies'));
        $this->assertTrue(Schema::hasTable('pharmacy_inventory'));
        $this->assertTrue(Schema::hasTable('invitation_deliveries'));
        $this->assertTrue(Schema::hasColumn('invitation_deliveries', 'claim_id'));
        $this->assertTrue(Schema::hasColumn('account_invitations', 'delivery_status'));
        $this->assertTrue(Schema::hasColumn('users', 'session_generation'));
        $this->assertSame('phase2-upgrade@example.test', DB::table('users')->where('id', $userId)->value('email'));

        for ($step = 0; $step < count(self::PHASE2); $step++) {
            $this->assertSame(0, Artisan::call('migrate:rollback', ['--force' => true, '--step' => 1]));
        }

        $this->assertFalse(Schema::hasTable('pharmacies'));
        $this->assertFalse(Schema::hasTable('invitation_deliveries'));
        $this->assertFalse(Schema::hasColumn('users', 'session_generation'));
        $this->assertTrue(Schema::hasColumn('users', 'public_id'));
        $this->assertSame('phase2-upgrade@example.test', DB::table('users')->where('id', $userId)->value('email'));
    }
}
