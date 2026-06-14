<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            // The chain should have redirected to login before reaching here; fail closed if it didn't.
            throw new AuthenticationException('Admin access required.');
        }

        if ($user->role !== UserRole::Admin) {
            throw new AuthenticationException(
                "Admin access required. Your account is signed in as [{$user->role->value}]; ask an operator to grant admin access."
            );
        }

        return $next($request);
    }
}
