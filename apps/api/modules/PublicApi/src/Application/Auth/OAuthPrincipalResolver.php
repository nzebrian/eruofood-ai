<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Application\Auth;

use EruoFood\PublicApi\Application\Service\OAuthService;

/**
 * Resolves an OAuth2 bearer access token to the shared {@see AuthenticatedContext}
 * by introspecting it through the {@see OAuthService}. Only bearer-scheme
 * credentials are considered; the X-Api-Key header is never treated as an OAuth
 * token. The resulting context is identical in shape to the API-key path, so the
 * gateway and every authorization check are agnostic to which was used.
 */
final readonly class OAuthPrincipalResolver implements PrincipalResolver
{
    public function __construct(private OAuthService $oauth)
    {
    }

    public function resolve(string $scheme, string $credential): ?AuthenticatedContext
    {
        if ($scheme !== 'bearer') {
            return null;
        }

        return $this->oauth->authenticateAccessToken($credential);
    }
}
