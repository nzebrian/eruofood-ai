<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Application\Service;

use DateTimeImmutable;
use EruoFood\PublicApi\Application\Auth\AuthenticatedContext;
use EruoFood\PublicApi\Application\Auth\IssuedToken;
use EruoFood\PublicApi\Application\Port\SecretHasher;
use EruoFood\PublicApi\Domain\Enum\OAuthGrant;
use EruoFood\PublicApi\Domain\Exception\OAuthError;
use EruoFood\PublicApi\Domain\OAuth\AccessToken;
use EruoFood\PublicApi\Domain\OAuth\AccessTokenRepository;
use EruoFood\PublicApi\Domain\OAuth\AuthorizationCode;
use EruoFood\PublicApi\Domain\OAuth\AuthorizationCodeRepository;
use EruoFood\PublicApi\Domain\OAuth\OAuthClient;
use EruoFood\PublicApi\Domain\OAuth\OAuthClientRepository;
use EruoFood\PublicApi\Domain\OAuth\RefreshToken;
use EruoFood\PublicApi\Domain\OAuth\RefreshTokenRepository;
use EruoFood\PublicApi\Domain\ValueObject\ScopeSet;

/**
 * The OAuth2 authorization server. It implements the three supported grants —
 * Authorization Code (with mandatory PKCE), Client Credentials, and Refresh
 * Token — plus access-token introspection. It is deliberately layered on the
 * same scope model as API keys: every token carries a {@see ScopeSet} and an
 * optional subject user id, and introspection returns the identical
 * {@see AuthenticatedContext} the API-key path produces, so scope enforcement
 * and BOLA are unchanged and unaware of the mechanism.
 *
 * Secrets are never stored in the clear: access tokens, refresh tokens and
 * authorization codes are persisted only as hashes, and confidential-client
 * secrets are verified in constant time.
 */
final readonly class OAuthService
{
    /**
     * @param array{access_ttl:int, refresh_ttl:int, code_ttl:int, access_prefix:string, refresh_prefix:string, code_prefix:string} $config
     */
    public function __construct(
        private OAuthClientRepository $clients,
        private AccessTokenRepository $accessTokens,
        private RefreshTokenRepository $refreshTokens,
        private AuthorizationCodeRepository $codes,
        private SecretHasher $hasher,
        private array $config,
    ) {
    }

    /**
     * Consent step: mint a single-use authorization code for a resource owner who
     * has just approved the client. Called from an authenticated (JWT) context —
     * the subject user is the logged-in resource owner, never client-supplied.
     */
    public function issueAuthorizationCode(
        string $clientId,
        string $subjectUserId,
        string $redirectUri,
        ScopeSet $requestedScopes,
        string $codeChallenge,
        string $codeChallengeMethod,
    ): string {
        $client = $this->requireClient($clientId);
        if (! $client->supportsGrant(OAuthGrant::AuthorizationCode)) {
            throw OAuthError::unsupportedGrantType('This client cannot use the authorization_code grant.');
        }
        if (! $client->redirectUriRegistered($redirectUri)) {
            throw OAuthError::invalidRequest('redirect_uri is not registered for this client.');
        }
        if (! in_array($codeChallengeMethod, ['S256', 'plain'], true) || $codeChallenge === '') {
            throw OAuthError::invalidRequest('A PKCE code_challenge (S256 preferred) is required.');
        }

        $plaintext = $this->randomToken($this->config['code_prefix']);
        $code = AuthorizationCode::issue(
            $this->codes->nextIdentity(),
            $this->hasher->hash($plaintext),
            $client->id(),
            $subjectUserId,
            $redirectUri,
            $client->scopesFor($requestedScopes),
            $codeChallenge,
            $codeChallengeMethod,
            $this->now()->modify('+'.$this->config['code_ttl'].' seconds'),
        );
        $this->codes->save($code);

        return $plaintext;
    }

    /** Authorization Code + PKCE exchange. */
    public function exchangeAuthorizationCode(
        string $clientId,
        ?string $clientSecret,
        string $presentedCode,
        string $redirectUri,
        string $codeVerifier,
    ): IssuedToken {
        $client = $this->requireClient($clientId);
        $this->authenticateClient($client, $clientSecret);

        $code = $this->codes->findByHash($this->hasher->hash($presentedCode));
        $now = $this->now();
        if ($code === null || ! $code->isUsable($now) || $code->clientId() !== $client->id()) {
            throw OAuthError::invalidGrant();
        }
        if (! hash_equals($code->redirectUri(), $redirectUri)) {
            throw OAuthError::invalidGrant('redirect_uri mismatch.');
        }
        if ($codeVerifier === '' || ! $code->verifyChallenge($codeVerifier)) {
            throw OAuthError::invalidGrant('PKCE verification failed.');
        }

        // Single use: burn the code before issuing tokens.
        $code->consume($now);
        $this->codes->save($code);

        return $this->issuePair($client, $code->subjectUserId(), $code->scopes(), $now);
    }

    /** Client Credentials grant — machine-to-machine, no subject, no refresh token. */
    public function clientCredentials(string $clientId, ?string $clientSecret, ScopeSet $requestedScopes): IssuedToken
    {
        $client = $this->requireClient($clientId);
        if (! $client->supportsGrant(OAuthGrant::ClientCredentials)) {
            throw OAuthError::unsupportedGrantType('This client cannot use the client_credentials grant.');
        }
        if (! $client->isConfidential()) {
            throw OAuthError::invalidClient('client_credentials requires a confidential client.');
        }
        $this->authenticateClient($client, $clientSecret);

        $now = $this->now();
        $access = $this->mintAccessToken($client, null, $client->scopesFor($requestedScopes), $now);

        return new IssuedToken($access['plaintext'], $this->config['access_ttl'], $access['token']->scopes());
    }

    /** Refresh Token grant — rotates the refresh token and issues a fresh pair. */
    public function refresh(string $clientId, ?string $clientSecret, string $presentedRefresh, ScopeSet $requestedScopes): IssuedToken
    {
        $client = $this->requireClient($clientId);
        if (! $client->supportsGrant(OAuthGrant::RefreshToken)) {
            throw OAuthError::unsupportedGrantType('This client cannot use the refresh_token grant.');
        }
        $this->authenticateClient($client, $clientSecret);

        $token = $this->refreshTokens->findByHash($this->hasher->hash($presentedRefresh));
        $now = $this->now();
        if ($token === null || ! $token->isUsable($now) || $token->clientId() !== $client->id()) {
            throw OAuthError::invalidGrant();
        }

        // A refresh may only narrow scopes, never widen them.
        $scopes = $requestedScopes->isEmpty() ? $token->scopes() : $requestedScopes->intersect($token->scopes());

        // Rotate: revoke the presented refresh token so reuse is dead.
        $token->revoke($now);
        $this->refreshTokens->save($token);

        return $this->issuePair($client, $token->subjectUserId(), $scopes, $now);
    }

    /** Introspect a presented access token to the shared authentication context. */
    public function authenticateAccessToken(string $presented): ?AuthenticatedContext
    {
        $token = $this->accessTokens->findByHash($this->hasher->hash($presented));
        if ($token === null || ! $token->isValid($this->now())) {
            return null;
        }

        return new AuthenticatedContext(
            applicationId: $token->applicationId(),
            developerId: $token->developerId(),
            scopes: $token->scopes(),
            subjectUserId: $token->subjectUserId(),
            authVia: 'oauth2',
            credentialId: $token->id(),
        );
    }

    // ---- internals ----------------------------------------------------------

    private function issuePair(OAuthClient $client, ?string $subjectUserId, ScopeSet $scopes, DateTimeImmutable $now): IssuedToken
    {
        $access = $this->mintAccessToken($client, $subjectUserId, $scopes, $now);

        $refreshPlain = $this->randomToken($this->config['refresh_prefix']);
        $refresh = RefreshToken::issue(
            $this->refreshTokens->nextIdentity(),
            $this->hasher->hash($refreshPlain),
            $access['token']->id(),
            $client->id(),
            $client->applicationId(),
            $client->developerId(),
            $subjectUserId,
            $scopes,
            $now->modify('+'.$this->config['refresh_ttl'].' seconds'),
            $now,
        );
        $this->refreshTokens->save($refresh);

        return new IssuedToken($access['plaintext'], $this->config['access_ttl'], $scopes, $refreshPlain);
    }

    /**
     * @return array{plaintext:string, token:AccessToken}
     */
    private function mintAccessToken(OAuthClient $client, ?string $subjectUserId, ScopeSet $scopes, DateTimeImmutable $now): array
    {
        $plaintext = $this->randomToken($this->config['access_prefix']);
        $token = AccessToken::issue(
            $this->accessTokens->nextIdentity(),
            $this->hasher->hash($plaintext),
            $client->id(),
            $client->applicationId(),
            $client->developerId(),
            $subjectUserId,
            $scopes,
            $now->modify('+'.$this->config['access_ttl'].' seconds'),
            $now,
        );
        $this->accessTokens->save($token);

        return ['plaintext' => $plaintext, 'token' => $token];
    }

    private function requireClient(string $clientId): OAuthClient
    {
        return $this->clients->findById($clientId) ?? throw OAuthError::invalidClient('Unknown client.');
    }

    /**
     * Confidential clients must present a valid secret; public clients (no stored
     * secret) authenticate solely via PKCE and must not send one.
     */
    private function authenticateClient(OAuthClient $client, ?string $presentedSecret): void
    {
        if (! $client->isConfidential()) {
            return;
        }
        $hash = $client->hashedSecret();
        if ($hash === null || $presentedSecret === null || $presentedSecret === '' || ! $this->hasher->verify($presentedSecret, $hash)) {
            throw OAuthError::invalidClient();
        }
    }

    private function randomToken(string $prefix): string
    {
        return $prefix.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
