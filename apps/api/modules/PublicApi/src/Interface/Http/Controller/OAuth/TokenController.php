<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Interface\Http\Controller\OAuth;

use EruoFood\PublicApi\Application\Auth\IssuedToken;
use EruoFood\PublicApi\Application\Service\OAuthService;
use EruoFood\PublicApi\Domain\Exception\OAuthError;
use EruoFood\PublicApi\Domain\ValueObject\ScopeSet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The OAuth2 token endpoint (`POST /public/v1/oauth/token`). It dispatches on
 * `grant_type` to the {@see OAuthService} for the Authorization Code (+PKCE),
 * Client Credentials and Refresh Token grants, and renders RFC 6749-compliant
 * token and error responses. Client credentials may be presented via HTTP Basic
 * or in the request body. This endpoint issues tokens only; every subsequent API
 * call authenticates the resulting access token through the same gateway as an
 * API key.
 */
final class TokenController
{
    public function __construct(private readonly OAuthService $oauth)
    {
    }

    public function issue(Request $request): JsonResponse
    {
        try {
            [$clientId, $clientSecret] = $this->clientCredentials($request);
            $grantType = (string) $request->input('grant_type', '');

            $token = match ($grantType) {
                'authorization_code' => $this->oauth->exchangeAuthorizationCode(
                    $clientId,
                    $clientSecret,
                    (string) $request->input('code', ''),
                    (string) $request->input('redirect_uri', ''),
                    (string) $request->input('code_verifier', ''),
                ),
                'client_credentials' => $this->oauth->clientCredentials(
                    $clientId,
                    $clientSecret,
                    $this->scopes($request),
                ),
                'refresh_token' => $this->oauth->refresh(
                    $clientId,
                    $clientSecret,
                    (string) $request->input('refresh_token', ''),
                    $this->scopes($request),
                ),
                default => throw OAuthError::unsupportedGrantType(),
            };

            return $this->tokenResponse($token);
        } catch (OAuthError $e) {
            return new JsonResponse(
                ['error' => $e->oauthError(), 'error_description' => $e->getMessage()],
                $e->oauthError() === 'invalid_client' ? 401 : 400,
            );
        }
    }

    private function tokenResponse(IssuedToken $token): JsonResponse
    {
        $body = [
            'access_token' => $token->accessToken,
            'token_type' => $token->tokenType,
            'expires_in' => $token->expiresIn,
            'scope' => implode(' ', $token->scopes->toArray()),
        ];
        if ($token->refreshToken !== null) {
            $body['refresh_token'] = $token->refreshToken;
        }

        return new JsonResponse($body, 200, [
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * @return array{0:string, 1:?string} [client_id, client_secret]
     */
    private function clientCredentials(Request $request): array
    {
        // HTTP Basic takes precedence over body parameters (RFC 6749 §2.3.1).
        $basicUser = $request->getUser();
        if (is_string($basicUser) && $basicUser !== '') {
            return [$basicUser, $request->getPassword()];
        }

        $clientId = (string) $request->input('client_id', '');
        if ($clientId === '') {
            throw OAuthError::invalidClient('client_id is required.');
        }
        $secret = $request->input('client_secret');

        return [$clientId, is_string($secret) && $secret !== '' ? $secret : null];
    }

    private function scopes(Request $request): ScopeSet
    {
        $scope = $request->input('scope');
        if (! is_string($scope) || trim($scope) === '') {
            return new ScopeSet([]);
        }

        return new ScopeSet(array_values(array_filter(explode(' ', $scope), static fn (string $s): bool => $s !== '')));
    }
}
