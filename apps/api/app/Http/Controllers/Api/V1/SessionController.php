<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Identity\SecurityEvent;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

final class SessionController extends Controller
{
    public function logout(Request $request): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
            SecurityEvent::info('token_revoked', (string) $request->headers->get('X-Request-Id'));
        }

        return ApiResponse::noContent();
    }

    public function logoutAll(Request $request): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $user->tokens()->delete();
            SecurityEvent::info('token_revoked', (string) $request->headers->get('X-Request-Id'));
        }

        return ApiResponse::noContent();
    }
}
