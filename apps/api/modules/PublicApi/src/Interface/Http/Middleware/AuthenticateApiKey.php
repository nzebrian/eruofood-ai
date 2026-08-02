<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Interface\Http\Middleware;

use Closure;
use EruoFood\PublicApi\Application\Service\ApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a public-API request from its API key (either `Authorization:
 * Bearer <key>` or the `X-Api-Key` header). On success the resolved application
 * id, key id and granted scopes are attached to the request; on failure a 401 in
 * the standard error envelope is returned. Never JWT — the public surface is
 * key-only.
 */
final readonly class AuthenticateApiKey
{
    public function __construct(private ApiKeyService $keys)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $presented = $request->bearerToken() ?? $request->header('X-Api-Key');

        $client = is_string($presented) && $presented !== '' ? $this->keys->authenticate($presented) : null;
        if ($client === null) {
            return new JsonResponse([
                'error' => ['code' => 'PUBLICAPI_UNAUTHENTICATED', 'message' => 'A valid API key is required.'],
            ], 401);
        }

        $request->attributes->set('publicapi_application_id', $client->application->id());
        $request->attributes->set('publicapi_developer_id', $client->application->developerId());
        $request->attributes->set('publicapi_key_id', $client->apiKey->id());
        $request->attributes->set('publicapi_scopes', $client->apiKey->scopes()->toArray());

        return $next($request);
    }
}
