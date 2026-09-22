<?php

declare(strict_types=1);

namespace App\Identity\Profile;

use App\Models\City;
use App\Models\UserAddress;

final class AddressProfile
{
    /**
     * @return array<string, mixed>
     */
    public static function from(UserAddress $address): array
    {
        $address->loadMissing('city');
        $city = $address->city;

        return [
            'id' => $address->public_id,
            'label' => $address->label,
            'address_text' => $address->address_text,
            'latitude' => (float) $address->latitude,
            'longitude' => (float) $address->longitude,
            'place_id' => $address->place_id,
            'is_default' => (bool) $address->is_default,
            'city' => $city instanceof City ? $city->name : '',
        ];
    }
}
