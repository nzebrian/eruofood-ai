<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Application\Auth;

use EruoFood\PublicApi\Application\Service\ApiKeyService;

/**
 * Resolves an API key (presented as a bearer token or in the X-Api-Key header)
 * to the shared {@see AuthenticatedContext}. It wraps the existing
 * {@see ApiKeyService} unchanged, so the key path behaves exactly as before —
 * this resolver only adapts its result into the mechanism-independent context.
 */
final readonly class ApiKeyPrincipalResolver implements PrincipalResolver
{
    public function __construct(private ApiKeyService $keys)
    {
    }

    public function resolve(string $scheme, string $credential): ?AuthenticatedContext
    {
        $client = $this->keys->authenticate($credential);
        if ($client === null) {
            return null;
        }

        return new AuthenticatedContext(
            applicationId: $client->application->id(),
            developerId: $client->application->developerId(),
            scopes: $client->apiKey->scopes(),
            subjectUserId: $client->apiKey->subjectUserId(),
            authVia: 'api_key',
            credentialId: $client->apiKey->id(),
        );
    }
}
