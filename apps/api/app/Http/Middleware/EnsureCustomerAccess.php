<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Exceptions\ApiProblem;
use App\Identity\SecurityEvent;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCustomerAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new ApiProblem('unauthenticated', 'Unauthenticated.', 401);
        }

        $requestId = (string) $request->headers->get('X-Request-Id');

        if (! $user->tokenCan('customer:access')) {
            SecurityEvent::warning('authorization_denied', $requestId);

            throw new ApiProblem('forbidden', 'This action is unauthorized.', 403);
        }

        if ($user->role !== UserRole::Customer) {
            SecurityEvent::warning('authorization_denied', $requestId);

            throw new ApiProblem('forbidden', 'This action is unauthorized.', 403);
        }

        if ($user->status !== AccountStatus::Active) {
            SecurityEvent::warning('authorization_denied', $requestId);

            throw new ApiProblem('account_inactive', 'This account is inactive.', 403);
        }

        return $next($request);
    }
}
