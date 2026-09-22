<?php

namespace Tests\Feature;

use App\Models\City;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CitySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_faisalabad_is_seeded_exactly_once_when_bootstrap_runs_twice(): void
    {
        $this->seed(CitySeeder::class);
        $this->seed(CitySeeder::class);

        $this->assertSame(1, City::query()->where('name', 'Faisalabad')->where('country_code', 'PK')->count());

        $city = City::query()->where('name', 'Faisalabad')->firstOrFail();
        $this->assertSame('Asia/Karachi', $city->timezone);
        $this->assertTrue($city->is_active);
    }

    public function test_seed_keeps_an_intentionally_inactive_faisalabad_inactive(): void
    {
        $this->seed(CitySeeder::class);

        $this->assertSame(1, City::query()->where('country_code', 'PK')->where('name', 'Faisalabad')->where('is_active', true)->count());

        City::query()->where('country_code', 'PK')->where('name', 'Faisalabad')->update([
            'is_active' => false,
            'timezone' => 'UTC',
        ]);

        $this->seed(CitySeeder::class);

        $cities = City::query()->where('country_code', 'PK')->where('name', 'Faisalabad')->get();
        $this->assertCount(1, $cities);
        $this->assertFalse($cities->first()->is_active);
        $this->assertSame('Asia/Karachi', $cities->first()->timezone);
    }

    public function test_seed_corrects_timezone_without_changing_activation(): void
    {
        $this->seed(CitySeeder::class);

        City::query()->where('country_code', 'PK')->where('name', 'Faisalabad')->update([
            'timezone' => 'UTC',
        ]);

        $this->seed(CitySeeder::class);

        $city = City::query()->where('country_code', 'PK')->where('name', 'Faisalabad')->firstOrFail();
        $this->assertSame(1, City::query()->where('country_code', 'PK')->where('name', 'Faisalabad')->count());
        $this->assertTrue($city->is_active);
        $this->assertSame('Asia/Karachi', $city->timezone);
    }
}
