<?php

namespace Tests\Feature;

use App\Identity\Otp\InMemoryOtpProvider;
use App\Identity\Otp\OtpHasher;
use App\Identity\Otp\OtpRedis;
use App\Identity\Phone\PakistaniMobileNormalizer;
use App\Models\City;
use App\Models\User;
use App\Models\UserAddress;
use Database\Seeders\CitySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserAddressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CitySeeder::class);
    }

    public function test_addresses_are_owned_capped_and_keep_one_default(): void
    {
        $token = $this->login('03001234567');
        $other = $this->login('03011234567');

        $created = $this->withCustomerToken($token)->postJson('/api/v1/me/addresses', $this->address('Home', false));
        $created->assertCreated()
            ->assertJsonPath('data.is_default', true)
            ->assertJsonPath('data.city', 'Faisalabad');
        $this->assertFalse(is_int($created->json('data.id')));

        $second = $this->withCustomerToken($token)->postJson('/api/v1/me/addresses', $this->address('Work', true));
        $second->assertCreated()->assertJsonPath('data.is_default', true);
        $this->withCustomerToken($token)->getJson('/api/v1/me/addresses/'.$created->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.is_default', false);

        $addressId = (string) $second->json('data.id');
        $this->withCustomerToken($other)->getJson('/api/v1/me/addresses/'.$addressId)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
        $this->withCustomerToken($other)->patchJson('/api/v1/me/addresses/'.$addressId, ['label' => 'Stolen'])
            ->assertNotFound();
        $this->withCustomerToken($other)->delete('/api/v1/me/addresses/'.$addressId)->assertNotFound();
        $this->withCustomerToken($token)->getJson('/api/v1/me/addresses/'.(string) Str::uuid())->assertNotFound();

        $this->withCustomerToken($token)->patchJson('/api/v1/me/addresses/'.$addressId, [
            'label' => 'Office',
            'user_id' => 1,
        ])->assertStatus(422);

        $this->withCustomerToken($token)->delete('/api/v1/me/addresses/'.$addressId)->assertNoContent();
        $this->withCustomerToken($token)->getJson('/api/v1/me/addresses/'.$created->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.is_default', true);

        $this->withCustomerToken($token)->postJson('/api/v1/me/addresses', [
            'label' => 'Bad',
            'address_text' => 'Street',
            'latitude' => 91,
            'longitude' => 73.08,
        ])->assertStatus(422);

        for ($index = UserAddress::query()->count(); $index < 10; $index++) {
            $this->withCustomerToken($token)->postJson('/api/v1/me/addresses', $this->address('Extra '.$index, false))
                ->assertCreated();
        }

        $this->withCustomerToken($token)->postJson('/api/v1/me/addresses', $this->address('Overflow', false))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'address_limit');
    }

    public function test_database_rejects_non_finite_and_out_of_range_coordinates(): void
    {
        $user = User::factory()->create();
        $cityId = (string) DB::table('cities')->where('name', 'Faisalabad')->value('id');

        foreach ([
            "'NaN'::numeric, 73.083000",
            "31.418000, 'NaN'::numeric",
            "'Infinity'::numeric, 73.083000",
            "31.418000, '-Infinity'::numeric",
            '90.000001, 73.083000',
            '31.418000, 180.000001',
            '-90.000001, 73.083000',
            '31.418000, -180.000001',
        ] as $coordinates) {
            DB::beginTransaction();

            try {
                DB::unprepared(sprintf(
                    "insert into user_addresses (public_id, user_id, city_id, label, address_text, latitude, longitude, is_default, created_at, updated_at) values ('%s', %d, '%s', 'Home', 'Street', %s, false, now(), now())",
                    (string) Str::uuid(),
                    $user->id,
                    $cityId,
                    $coordinates,
                ));
                DB::rollBack();
                $this->fail('Coordinate insert should have been rejected: '.$coordinates);
            } catch (QueryException) {
                DB::rollBack();
                $this->assertTrue(true);
            }
        }

        DB::unprepared(sprintf(
            "insert into user_addresses (public_id, user_id, city_id, label, address_text, latitude, longitude, is_default, created_at, updated_at) values ('%s', %d, '%s', 'Edge', 'Street', -90, -180, false, now(), now())",
            (string) Str::uuid(),
            $user->id,
            $cityId,
        ));
        DB::unprepared(sprintf(
            "insert into user_addresses (public_id, user_id, city_id, label, address_text, latitude, longitude, is_default, created_at, updated_at) values ('%s', %d, '%s', 'Edge', 'Street', 90, 180, false, now(), now())",
            (string) Str::uuid(),
            $user->id,
            $cityId,
        ));

        DB::beginTransaction();

        try {
            DB::unprepared(sprintf(
                "insert into user_addresses (public_id, user_id, city_id, label, address_text, longitude, is_default, created_at, updated_at) values ('%s', %d, '%s', 'Home', 'Street', 73, false, now(), now())",
                (string) Str::uuid(),
                $user->id,
                $cityId,
            ));
            DB::rollBack();
            $this->fail('Null latitude should have been rejected.');
        } catch (QueryException) {
            DB::rollBack();
            $this->assertTrue(true);
        }
    }

    public function test_inactive_faisalabad_makes_address_creation_unavailable(): void
    {
        City::query()->where('country_code', 'PK')->where('name', 'Faisalabad')->update(['is_active' => false]);
        $token = $this->login('03071234567');

        $this->withCustomerToken($token)->postJson('/api/v1/me/addresses', $this->address('Home', false))
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'address_unavailable');
    }

    public function test_address_city_comes_from_the_related_city(): void
    {
        $token = $this->login('03072234567');
        $created = $this->withCustomerToken($token)->postJson('/api/v1/me/addresses', $this->address('Home', true))
            ->assertCreated();
        City::query()->where('country_code', 'PK')->where('name', 'Faisalabad')->update(['name' => 'Lyallpur']);

        $this->withCustomerToken($token)->getJson('/api/v1/me/addresses/'.$created->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.city', 'Lyallpur');
    }

    public function test_patch_cannot_clear_the_default_flag(): void
    {
        $token = $this->login('03073234567');
        $created = $this->withCustomerToken($token)->postJson('/api/v1/me/addresses', $this->address('Home', true))
            ->assertCreated()
            ->assertJsonPath('data.is_default', true);

        $this->withCustomerToken($token)->patchJson('/api/v1/me/addresses/'.$created->json('data.id'), [
            'is_default' => false,
        ])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

        $this->withCustomerToken($token)->getJson('/api/v1/me/addresses/'.$created->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.is_default', true);
    }

    public function test_profile_updates_do_not_revoke_tokens(): void
    {
        $token = $this->login('03001234567');
        $user = User::query()->where('phone_e164', '+923001234567')->firstOrFail();
        DB::table('users')->where('id', $user->id)->update(['full_name' => 'Still Active']);
        $this->assertSame(1, $user->tokens()->count());
        $this->withCustomerToken($token)->getJson('/api/v1/me')->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function address(string $label, bool $default): array
    {
        return [
            'label' => $label,
            'address_text' => 'Satiana Road, Faisalabad',
            'latitude' => 31.418000,
            'longitude' => 73.079000,
            'place_id' => 'local-place',
            'is_default' => $default,
        ];
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

        return (string) $this->postJson('/api/v1/auth/otp/verify', [
            'phone_number' => $phone,
            'challenge_id' => $challengeId,
            'otp' => (string) app(InMemoryOtpProvider::class)->codeFor($challengeId),
        ])->assertOk()->json('data.access_token');
    }
}
