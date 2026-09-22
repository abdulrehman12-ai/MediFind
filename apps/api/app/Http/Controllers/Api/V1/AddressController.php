<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAddressRequest;
use App\Http\Requests\UpdateAddressRequest;
use App\Identity\AddressBook;
use App\Identity\Profile\AddressProfile;
use App\Models\User;
use App\Models\UserAddress;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class AddressController extends Controller
{
    public function index(Request $request, AddressBook $addresses): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $rows = $user->addresses()->with('city')->orderByDesc('is_default')->orderBy('id')->get()
            ->map(fn (UserAddress $address): array => AddressProfile::from($address))
            ->all();

        return ApiResponse::data($request, ['addresses' => $rows]);
    }

    public function store(StoreAddressRequest $request, AddressBook $addresses): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $address = $addresses->create($user, $request->validated());

        return ApiResponse::data($request, AddressProfile::from($address), 201);
    }

    public function show(Request $request, string $address, AddressBook $addresses): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::data($request, AddressProfile::from($addresses->findOwned($user, $address)));
    }

    public function update(UpdateAddressRequest $request, string $address, AddressBook $addresses): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $owned = $addresses->findOwned($user, $address);
        $updated = $addresses->update($user, $owned, $request->validated());

        return ApiResponse::data($request, AddressProfile::from($updated));
    }

    public function destroy(Request $request, string $address, AddressBook $addresses): Response
    {
        /** @var User $user */
        $user = $request->user();
        $addresses->delete($user, $addresses->findOwned($user, $address));

        return ApiResponse::noContent();
    }
}
