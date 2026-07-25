<?php

declare(strict_types=1);

namespace EruoFood\Identity\Interface\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Role gate. Usage: ->middleware('role:admin') or 'role:admin,moderator'.
 * Runs after JwtAuthenticate, which populates the request's roles.
 */
final class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        /** @var list<string> $userRoles */
        $userRoles = $request->attributes->get('auth_roles', []);

        if (count(array_intersect($roles, $userRoles)) === 0) {
            return new JsonResponse([
                'error' => ['code' => 'FORBIDDEN', 'message' => 'You do not have permission to perform this action.'],
            ], 403);
        }

        return $next($request);
    }
}
