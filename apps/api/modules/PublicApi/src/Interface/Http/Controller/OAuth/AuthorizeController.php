<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Interface\Http\Controller\OAuth;

use EruoFood\PublicApi\Application\Service\OAuthService;
use EruoFood\PublicApi\Domain\Exception\OAuthError;
use EruoFood\PublicApi\Domain\ValueObject\ScopeSet;
use EruoFood\PublicApi\Interface\Http\Concerns\ResolvesDeveloper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Authorization Code consent step (`POST /v1/oauth/authorize`). It runs
 * inside the internal JWT session, so the consenting resource owner is the
 * logged-in user — the subject user id is taken from the authenticated session,
 * never from a request parameter, which is what keeps the resulting token's
 * object-level authorization sound. On approval it returns a single-use
 * authorization code the client exchanges (with its PKCE verifier) at the token
 * endpoint.
 */
final class AuthorizeController
{
    use ResolvesDeveloper;

    public function __construct(private readonly OAuthService $oauth)
    {
    }

    public function approve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'string'],
            'redirect_uri' => ['required', 'string', 'url'],
            'scope' => ['nullable', 'string'],
            'code_challenge' => ['required', 'string'],
            'code_challenge_method' => ['required', 'string', 'in:S256,plain'],
            'state' => ['nullable', 'string'],
        ]);

        try {
            $code = $this->oauth->issueAuthorizationCode(
                $data['client_id'],
                $this->currentUserId($request),
                $data['redirect_uri'],
                $this->scopes($data['scope'] ?? null),
                $data['code_challenge'],
                $data['code_challenge_method'],
            );
        } catch (OAuthError $e) {
            return new JsonResponse(['error' => $e->oauthError(), 'error_description' => $e->getMessage()], 400);
        }

        return new JsonResponse([
            'code' => $code,
            'redirect_uri' => $data['redirect_uri'],
            'state' => $data['state'] ?? null,
        ], 201, ['Cache-Control' => 'no-store']);
    }

    private function scopes(?string $scope): ScopeSet
    {
        if ($scope === null || trim($scope) === '') {
            return new ScopeSet([]);
        }

        return new ScopeSet(array_values(array_filter(explode(' ', $scope), static fn (string $s): bool => $s !== '')));
    }
}
