<?php

namespace Database\Seeders;

use App\Models\City;
use Illuminate\Database\Seeder;
use RuntimeException;

class CitySeeder extends Seeder
{
    public function run(): void
    {
        $city = City::query()
            ->where('country_code', 'PK')
            ->where('name', 'Faisalabad')
            ->first();

        if ($city === null) {
            $city = City::query()->create([
                'country_code' => 'PK',
                'name' => 'Faisalabad',
                'timezone' => 'Asia/Karachi',
                'is_active' => true,
            ]);
        } elseif ($city->timezone !== 'Asia/Karachi') {
            City::query()->whereKey($city->getKey())->update([
                'timezone' => 'Asia/Karachi',
            ]);
            $city->timezone = 'Asia/Karachi';
        }

        if ($city->name !== 'Faisalabad' || $city->country_code !== 'PK' || $city->timezone !== 'Asia/Karachi') {
            throw new RuntimeException('Faisalabad city seed could not be applied.');
        }
    }
}
