<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Interface\Http\Middleware;

use Closure;
use EruoFood\PublicApi\Domain\ValueObject\Scope;
use EruoFood\PublicApi\Domain\ValueObject\ScopeSet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces that the authenticated key was granted the scope a route requires
 * (declared as `publicapi.scope:foods:read`). Clients receive only explicitly
 * granted permissions — a missing scope is a 403, never a silent allow.
 */
final readonly class EnforceScope
{
    public function handle(Request $request, Closure $next, string $required): Response
    {
        /** @var list<string> $granted */
        $granted = $request->attributes->get('publicapi_scopes', []);

        if (! (new ScopeSet($granted))->grants(new Scope($required))) {
            return new JsonResponse([
                'error' => [
                    'code' => 'PUBLICAPI_FORBIDDEN',
                    'message' => sprintf('This API key is missing the required scope "%s".', $required),
                ],
            ], 403);
        }

        return $next($request);
    }
}
