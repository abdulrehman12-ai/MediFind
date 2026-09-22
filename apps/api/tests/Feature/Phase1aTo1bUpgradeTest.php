<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('phase1b-upgrade')]
class Phase1aTo1bUpgradeTest extends TestCase
{
    private const PHASE1A = [
        'database/migrations/0001_01_01_000000_create_users_table.php',
        'database/migrations/0001_01_01_000001_create_cache_table.php',
        'database/migrations/0001_01_01_000002_create_jobs_table.php',
        'database/migrations/2026_09_21_000000_create_cities_table.php',
    ];

    protected function tearDown(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true, '--seed' => true]);

        parent::tearDown();
    }

    public function test_phase_1b_upgrades_a_phase_1a_database_and_rolls_back_in_order(): void
    {
        $this->assertSame('medifind_testing', DB::selectOne('select current_database() as db')->db);
        $this->assertSame('medifind_test', DB::selectOne('select current_user as usr')->usr);

        $this->assertSame(0, Artisan::call('db:wipe', ['--force' => true, '--database' => 'pgsql']));
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--path' => self::PHASE1A]));

        $password = Hash::make('phase1a-sentinel-password');
        $id = DB::table('users')->insertGetId([
            'name' => 'Legacy User',
            'email' => 'sentinel-phase1a@example.test',
            'password' => $password,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));

        $row = DB::table('users')->where('id', $id)->first();
        $this->assertNotNull($row);
        $this->assertSame('sentinel-phase1a@example.test', $row->email);
        $this->assertSame($password, $row->password);
        $this->assertSame('Legacy User', $row->name);
        $this->assertSame('Legacy User', $row->full_name);
        $this->assertSame('customer', $row->role);
        $this->assertSame('active', $row->status);
        $this->assertNull($row->phone_e164);
        $this->assertNull($row->deleted_at);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', (string) $row->public_id);

        $otpUser = new User;
        $otpUser->forceFill([
            'public_id' => (string) Str::uuid(),
            'full_name' => null,
            'email' => null,
            'password' => null,
            'name' => null,
            'role' => UserRole::Customer,
            'status' => AccountStatus::Active,
        ]);
        $otpUser->save();
        $this->assertNull($otpUser->fresh()->email);
        $this->assertNull($otpUser->fresh()->password);
        $this->assertNull($otpUser->fresh()->full_name);

        $migrations = DB::table('migrations')->orderBy('id')->pluck('migration')->all();
        $this->assertSame([
            '2026_09_22_100000_alter_users_for_identity',
            '2026_09_22_100100_create_personal_access_tokens',
            '2026_09_22_100200_create_user_token_revocation_trigger',
            '2026_09_22_100300_create_user_addresses',
            '2026_09_22_100400_revoke_tokens_on_customer_role_change',
        ], array_slice($migrations, -5));

        $this->assertNotNull(DB::selectOne("select 1 from pg_indexes where indexname = 'users_public_id_unique'"));
        $this->assertNotNull(DB::selectOne("select 1 from pg_indexes where indexname = 'users_phone_e164_active_unique'"));
        $this->assertNotNull(DB::selectOne("select 1 from pg_indexes where indexname = 'users_email_active_unique'"));
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'users_role_check'"));
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'users_status_check'"));
        $this->assertNotNull(DB::selectOne("select 1 from pg_trigger where tgname = 'medifind_user_token_revocation' and not tgisinternal"));
        $this->assertNotNull(DB::selectOne("select 1 from pg_proc where proname = 'medifind_revoke_tokens_on_account_block'"));
        $this->assertStringContainsString('UPDATE OF status, deleted_at, role', $this->triggerDefinition());
        $this->assertStringContainsString("OLD.role = 'customer'", $this->revocationFunctionDefinition());
        $this->assertNotNull(DB::selectOne("select 1 from information_schema.columns where table_name = 'user_addresses' and column_name = 'location'"));
        $this->assertNotNull(DB::selectOne("select 1 from pg_indexes where indexname = 'user_addresses_location_gist' and indexdef ilike '%gist%'"));
        $this->assertNotNull(DB::selectOne("select 1 from pg_indexes where indexname = 'user_addresses_one_default_unique'"));
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'user_addresses_latitude_check'"));
        $this->assertNotNull(DB::selectOne("select 1 from pg_constraint where conname = 'user_addresses_longitude_check'"));

        $otpUser->delete();
        $otpUser->forceDelete();

        $this->assertSame(0, Artisan::call('migrate:rollback', ['--force' => true, '--step' => 1]));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('user_addresses'));
        $this->assertNotNull(DB::selectOne("select 1 from pg_trigger where tgname = 'medifind_user_token_revocation' and not tgisinternal"));
        $this->assertStringContainsString('UPDATE OF status, deleted_at ON', $this->triggerDefinition());
        $this->assertStringNotContainsString('role', $this->triggerDefinition());
        $this->assertStringNotContainsString('OLD.role', $this->revocationFunctionDefinition());

        $this->assertSame(0, Artisan::call('migrate:rollback', ['--force' => true, '--step' => 1]));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('user_addresses'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('personal_access_tokens'));
        $this->assertNotNull(DB::selectOne("select 1 from pg_trigger where tgname = 'medifind_user_token_revocation' and not tgisinternal"));

        $this->assertSame(0, Artisan::call('migrate:rollback', ['--force' => true, '--step' => 1]));
        $this->assertNull(DB::selectOne("select 1 from pg_trigger where tgname = 'medifind_user_token_revocation' and not tgisinternal"));
        $this->assertNull(DB::selectOne("select 1 from pg_proc where proname = 'medifind_revoke_tokens_on_account_block'"));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('personal_access_tokens'));

        $this->assertSame(0, Artisan::call('migrate:rollback', ['--force' => true, '--step' => 1]));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('personal_access_tokens'));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('users', 'public_id'));

        DB::table('users')->insert([
            'public_id' => (string) Str::uuid(),
            'name' => null,
            'email' => null,
            'password' => null,
            'role' => 'customer',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            Artisan::call('migrate:rollback', ['--force' => true, '--step' => 1]);
            $this->fail('Rollback should abort while OTP users exist.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('OTP users exist', $exception->getMessage());
        }

        DB::reconnect();
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('users', 'phone_e164'));
        $this->assertNotNull(DB::table('users')->where('id', $id)->first());

        DB::table('users')->whereNull('email')->delete();
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--force' => true, '--step' => 1]));
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('users', 'public_id'));
        $restored = DB::table('users')->where('id', $id)->first();
        $this->assertNotNull($restored);
        $this->assertSame($password, $restored->password);
        $this->assertSame('Legacy User', $restored->name);
        $this->assertSame('sentinel-phase1a@example.test', $restored->email);

        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $upgraded = DB::table('users')->where('id', $id)->first();
        $this->assertNotNull($upgraded);
        $this->assertSame($password, $upgraded->password);
        $this->assertNotNull($upgraded->public_id);
        $this->assertSame('customer', $upgraded->role);
    }

    private function triggerDefinition(): string
    {
        $row = DB::selectOne("select pg_get_triggerdef(oid) as def from pg_trigger where tgname = 'medifind_user_token_revocation' and not tgisinternal");

        return (string) ($row->def ?? '');
    }

    private function revocationFunctionDefinition(): string
    {
        $row = DB::selectOne("select pg_get_functiondef(oid) as def from pg_proc where proname = 'medifind_revoke_tokens_on_account_block'");

        return (string) ($row->def ?? '');
    }
}
