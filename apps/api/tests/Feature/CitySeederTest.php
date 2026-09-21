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
}
