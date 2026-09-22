<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\User;
use App\Models\UserAddress;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SerializationPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_generic_serialization_hides_internal_identity_fields(): void
    {
        $this->seed(CitySeeder::class);
        $user = User::factory()->create([
            'phone_e164' => '+923001234567',
            'name' => 'Legacy Name',
        ]);
        $userArray = $user->fresh()->toArray();

        foreach (['id', 'phone_e164', 'password', 'remember_token', 'name'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $userArray);
        }

        $cityId = City::query()->where('name', 'Faisalabad')->value('id');
        $address = new UserAddress;
        $address->forceFill([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'city_id' => $cityId,
            'label' => 'Home',
            'address_text' => 'Satiana Road',
            'latitude' => 31.418,
            'longitude' => 73.079,
            'is_default' => true,
        ]);
        $address->save();
        $addressArray = $address->fresh()->toArray();

        foreach (['id', 'user_id', 'city_id', 'location'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $addressArray);
        }
    }
}
