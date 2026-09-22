<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Identity\AccountStatusService;
use App\Identity\Otp\InMemoryOtpProvider;
use App\Identity\Otp\OtpHasher;
use App\Identity\Otp\OtpRedis;
use App\Identity\Phone\PakistaniMobileNormalizer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_tokens_and_account_state_follow_the_access_rules(): void
    {
        $token = $this->login('03001234567');
        $me = $this->withCustomerToken($token)->getJson('/api/v1/me');
        $this->assertStringNotContainsString('+923001234567', (string) $me->getContent());
        $me->assertOk()
            ->assertJsonPath('data.phone_masked', '+92300***4567')
            ->assertJsonMissingPath('data.phone_number')
            ->assertJsonMissingPath('data.password');
        $this->assertFalse(is_int($me->json('data.id')));

        $this->withCustomerToken($token)->patchJson('/api/v1/me', [
            'full_name' => 'Ayesha Khan',
            'email' => 'Ayesha@Example.test',
        ])->assertOk()
            ->assertJsonPath('data.full_name', 'Ayesha Khan')
            ->assertJsonPath('data.email', 'ayesha@example.test');

        $this->withCustomerToken($token)->patchJson('/api/v1/me', [
            'role' => 'admin',
            'status' => 'suspended',
            'phone_number' => '03009999999',
            'password' => 'secret-secret',
            'name' => 'Ignored',
        ])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

        $user = User::query()->where('phone_e164', '+923001234567')->firstOrFail();
        $this->assertSame(UserRole::Customer, $user->role);
        $this->assertSame('Ayesha Khan', $user->full_name);
        $this->assertNotSame('Ignored', $user->name);

        $expired = $user->createToken('customer', ['customer:access'], now()->subMinute());
        $this->withCustomerToken($expired->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');

        $other = $user->createToken('other', ['pharmacy:access'], now()->addDay());
        $this->withCustomerToken($other->plainTextToken)->getJson('/api/v1/me')->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');

        $this->withCustomerToken('not-a-token')->getJson('/api/v1/me')->assertUnauthorized();

        $second = $user->createToken('customer', ['customer:access'], now()->addDays(30));
        $this->withCustomerToken($token)->post('/api/v1/auth/logout')->assertNoContent();
        $this->withCustomerToken($token)->getJson('/api/v1/me')->assertUnauthorized();
        $this->withCustomerToken($second->plainTextToken)->getJson('/api/v1/me')->assertOk();
        $third = $user->createToken('customer', ['customer:access'], now()->addDays(30));
        $this->withCustomerToken($second->plainTextToken)->post('/api/v1/auth/logout-all')->assertNoContent();
        $this->withCustomerToken($second->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized();
        $this->withCustomerToken($third->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized();

        $fresh = $this->login('03001234567');
        $owner = User::query()->where('phone_e164', '+923001234567')->firstOrFail();
        app(AccountStatusService::class)->suspend($owner);
        $this->assertSame(0, $owner->tokens()->count());
        $this->withCustomerToken($fresh)->getJson('/api/v1/me')->assertUnauthorized();
        app(AccountStatusService::class)->reactivate($owner->fresh());
        $this->assertSame(0, $owner->fresh()->tokens()->count());
        $this->withCustomerToken($fresh)->getJson('/api/v1/me')->assertUnauthorized();

        DB::statement('ALTER TABLE users DISABLE TRIGGER medifind_user_token_revocation');
        $owner = $owner->fresh();
        $kept = $owner->createToken('customer', ['customer:access'], now()->addDay());
        $owner->status = AccountStatus::Suspended;
        $owner->save();
        DB::statement('ALTER TABLE users ENABLE TRIGGER medifind_user_token_revocation');
        $this->withCustomerToken($kept->plainTextToken)->getJson('/api/v1/me')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'account_inactive');
        $owner->status = AccountStatus::Active;
        $owner->save();

        $again = $this->login('03001234567');
        $owner = User::query()->where('phone_e164', '+923001234567')->firstOrFail();
        $owner->delete();
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $owner->id)->count());
        $this->withCustomerToken($again)->getJson('/api/v1/me')->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_database_role_removal_deletes_tokens_and_does_not_restore_them(): void
    {
        $plain = $this->login('03061234011');
        $user = User::query()->where('phone_e164', '+923061234011')->firstOrFail();

        DB::table('users')->where('id', $user->id)->update(['full_name' => 'Still Active']);
        $this->assertSame(1, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());
        $this->withCustomerToken($plain)->getJson('/api/v1/me')->assertOk();

        foreach (['pharmacy_staff', 'admin'] as $role) {
            DB::table('users')->where('id', $user->id)->update(['role' => $role]);
            $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());

            DB::table('users')->where('id', $user->id)->update(['role' => 'customer']);
            $this->withCustomerToken($plain)->getJson('/api/v1/me')->assertUnauthorized();

            $issued = $user->fresh()->createToken('customer', ['customer:access'], now()->addDay());
            $plain = $issued->plainTextToken;
            $this->withCustomerToken($plain)->getJson('/api/v1/me')->assertOk();
        }

        DB::table('users')->where('id', $user->id)->update(['status' => 'suspended']);
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());
        $this->withCustomerToken($plain)->getJson('/api/v1/me')->assertUnauthorized();

        DB::table('users')->where('id', $user->id)->update(['status' => 'active']);
        $this->withCustomerToken($plain)->getJson('/api/v1/me')->assertUnauthorized();

        $kept = $user->fresh()->createToken('customer', ['customer:access'], now()->addDay());
        $this->withCustomerToken($kept->plainTextToken)->getJson('/api/v1/me')->assertOk();

        DB::table('users')->where('id', $user->id)->update(['deleted_at' => now()]);
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count());
        $this->withCustomerToken($kept->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_otp_login_refuses_staff_and_admin_identities(): void
    {
        foreach ([UserRole::PharmacyStaff, UserRole::Admin] as $role) {
            $phone = $role === UserRole::Admin ? '+923211234567' : '+923221234567';
            $input = $role === UserRole::Admin ? '03211234567' : '03221234567';
            User::query()->forceCreate([
                'public_id' => (string) Str::uuid(),
                'phone_e164' => $phone,
                'phone_verified_at' => now(),
                'role' => $role,
                'status' => AccountStatus::Active,
            ]);

            $requested = $this->postJson('/api/v1/auth/otp/request', ['phone_number' => $input])->assertStatus(202);
            $challengeId = (string) $requested->json('data.challenge_id');
            $this->postJson('/api/v1/auth/otp/verify', [
                'phone_number' => $input,
                'challenge_id' => $challengeId,
                'otp' => (string) app(InMemoryOtpProvider::class)->codeFor($challengeId),
            ])->assertForbidden()->assertJsonPath('error.code', 'account_role_forbidden');
        }
    }

    public function test_profile_normalization_and_duplicate_email_use_the_validation_envelope(): void
    {
        $token = $this->login('03081234001');
        $updated = $this->withCustomerToken($token)->patchJson('/api/v1/me', [
            'full_name' => '  Ayesha Khan  ',
            'email' => '  Mixed@Example.test  ',
        ])->assertOk();
        $updated->assertJsonPath('data.full_name', 'Ayesha Khan')
            ->assertJsonPath('data.email', 'mixed@example.test')
            ->assertJsonPath('data.phone_masked', '+92308***4001');
        $this->assertStringNotContainsString('+923081234001', (string) $updated->getContent());

        $this->withCustomerToken($token)->patchJson('/api/v1/me', [
            'full_name' => '   ',
            'email' => '',
        ])->assertOk()
            ->assertJsonPath('data.full_name', null)
            ->assertJsonPath('data.email', null);

        $this->withCustomerToken($token)->patchJson('/api/v1/me', [
            'role' => 'admin',
        ])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

        $this->withCustomerToken($token)->patchJson('/api/v1/me', [
            'email' => 'owner@example.test',
        ])->assertOk();
        $other = $this->login('03081234002');
        $this->withCustomerToken($other)->patchJson('/api/v1/me', [
            'email' => 'OWNER@example.test',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['email']], 'meta' => ['request_id']]);
    }

    protected function tearDown(): void
    {
        app(OtpRedis::class)->deleteNamespace();

        parent::tearDown();
    }

    private function login(string $phone): string
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

        return (string) $verified->json('data.access_token');
    }
}
