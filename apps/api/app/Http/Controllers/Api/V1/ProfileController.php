<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Identity\Profile\CustomerProfile;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::data($request, CustomerProfile::from($user));
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $attributes = $request->validated();

        if (array_key_exists('email', $attributes) && is_string($attributes['email'])) {
            $attributes['email'] = strtolower($attributes['email']);
        }

        try {
            $user->fill($attributes);
            $user->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'email' => ['The email has already been taken.'],
            ]);
        }

        return ApiResponse::data($request, CustomerProfile::from($user->refresh()));
    }
}
