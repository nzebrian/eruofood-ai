<?php

declare(strict_types=1);

namespace EruoFood\Identity\Interface\Http\Middleware;

use Closure;
use EruoFood\Identity\Application\Port\TokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a request from its Bearer access token. On success the verified
 * user id and roles are attached to the request; on failure a 401 is returned.
 * Stateless — no session, matching the JWT design (MASTER_PLAN.md §7.2).
 */
final readonly class JwtAuthenticate
{
    public function __construct(private TokenIssuer $tokens)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $claims = $token !== null ? $this->tokens->parse($token) : null;

        if ($claims === null) {
            return new JsonResponse([
                'error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Authentication required.'],
            ], 401);
        }

        $request->attributes->set('auth_user_id', $claims->userId);
        $request->attributes->set('auth_roles', $claims->roles);

        return $next($request);
    }
}
