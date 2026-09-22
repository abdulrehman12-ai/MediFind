<?php

declare(strict_types=1);

namespace App\Identity;

use App\Exceptions\ApiProblem;
use App\Models\City;
use App\Models\User;
use App\Models\UserAddress;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AddressBook
{
    public const LIMIT = 10;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $user, array $attributes): UserAddress
    {
        return $this->attempt(fn (): UserAddress => $this->insert($user, $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insert(User $user, array $attributes): UserAddress
    {
        return DB::transaction(function () use ($user, $attributes): UserAddress {
            $this->lock($user);

            $count = UserAddress::query()->where('user_id', $user->id)->count();

            if ($count >= self::LIMIT) {
                throw new ApiProblem('address_limit', 'The address limit has been reached.', 422);
            }

            $makeDefault = $count === 0 || ($attributes['is_default'] ?? false) === true || ($attributes['is_default'] ?? false) === 1;

            if ($makeDefault) {
                $this->clearDefault($user);
            }

            $city = $this->activeFaisalabad();
            $address = new UserAddress;
            $address->forceFill([
                'public_id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'city_id' => $city->id,
                'label' => $attributes['label'],
                'address_text' => $attributes['address_text'],
                'latitude' => $attributes['latitude'],
                'longitude' => $attributes['longitude'],
                'place_id' => $attributes['place_id'] ?? null,
                'is_default' => $makeDefault,
            ]);
            $address->save();
            $saved = $address->refresh();
            $saved->setRelation('city', $city);

            return $saved;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $user, UserAddress $address, array $attributes): UserAddress
    {
        $this->assertOwner($user, $address);

        return $this->attempt(fn (): UserAddress => $this->applyUpdate($user, $address, $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function applyUpdate(User $user, UserAddress $address, array $attributes): UserAddress
    {
        return DB::transaction(function () use ($user, $address, $attributes): UserAddress {
            $this->lock($user);
            $address->refresh();
            $this->assertOwner($user, $address);

            if (array_key_exists('is_default', $attributes)) {
                if ($attributes['is_default'] !== true && $attributes['is_default'] !== 1) {
                    throw new ApiProblem('validation_failed', 'The given data was invalid.', 422, [
                        'is_default' => ['The address can only be promoted to the default.'],
                    ]);
                }

                $this->clearDefault($user);
                $address->is_default = true;
            }

            foreach (['label', 'address_text', 'latitude', 'longitude', 'place_id'] as $field) {
                if (array_key_exists($field, $attributes)) {
                    $address->{$field} = $attributes[$field];
                }
            }

            $address->save();

            return $address->refresh()->load('city');
        });
    }

    public function delete(User $user, UserAddress $address): void
    {
        $this->assertOwner($user, $address);

        DB::transaction(function () use ($user, $address): void {
            $this->lock($user);
            $address->refresh();
            $this->assertOwner($user, $address);
            $wasDefault = $address->is_default;
            $address->delete();

            if (! $wasDefault) {
                return;
            }

            $next = UserAddress::query()
                ->where('user_id', $user->id)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->first();

            if ($next !== null) {
                $next->is_default = true;
                $next->save();
            }
        });
    }

    public function findOwned(User $user, string $publicId): UserAddress
    {
        $address = UserAddress::query()
            ->with('city')
            ->where('public_id', $publicId)
            ->where('user_id', $user->id)
            ->first();

        if ($address === null) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }

        return $address;
    }

    private function assertOwner(User $user, UserAddress $address): void
    {
        if ($address->user_id !== $user->id) {
            throw new ApiProblem('not_found', 'The requested resource was not found.', 404);
        }
    }

    private function lock(User $user): void
    {
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['user_addresses:'.$user->id]);
    }

    private function clearDefault(User $user): void
    {
        UserAddress::query()->where('user_id', $user->id)->update(['is_default' => false]);
    }

    /**
     * @param  callable(): UserAddress  $write
     */
    private function attempt(callable $write): UserAddress
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $first) {
            if (! DefaultAddressConflict::isOneDefault($first)) {
                throw $first;
            }
        }

        try {
            return $write();
        } catch (UniqueConstraintViolationException $second) {
            DefaultAddressConflict::rejectSecond($second);
        }
    }

    private function activeFaisalabad(): City
    {
        $city = City::query()
            ->where('country_code', 'PK')
            ->where('name', 'Faisalabad')
            ->where('is_active', true)
            ->first();

        if (! $city instanceof City) {
            throw new ApiProblem('address_unavailable', 'Addresses are temporarily unavailable.', 503);
        }

        return $city;
    }
}
