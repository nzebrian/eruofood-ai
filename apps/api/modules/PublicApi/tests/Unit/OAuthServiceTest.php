<?php

declare(strict_types=1);

use EruoFood\PublicApi\Application\Service\OAuthService;
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
use EruoFood\PublicApi\Infrastructure\Security\Sha256SecretHasher;

/**
 * OAuth2 grant behaviour, exercised against in-memory repositories and the real
 * SHA-256 hasher — no database. Proves the three grants, PKCE, single-use codes,
 * refresh rotation, scope containment, and the shared authentication context.
 */
function makeOAuth(): array
{
    $clients = new class () implements OAuthClientRepository {
        public array $s = [];
        private int $n = 0;
        public function nextIdentity(): string
        {
            return 'c'.(++$this->n);
        }
        public function findById(string $id): ?OAuthClient
        {
            return $this->s[$id] ?? null;
        }
        public function save(OAuthClient $c): void
        {
            $this->s[$c->id()] = $c;
        }
    };
    $codes = new class () implements AuthorizationCodeRepository {
        public array $s = [];
        private int $n = 0;
        public function nextIdentity(): string
        {
            return 'code'.(++$this->n);
        }
        public function findByHash(string $h): ?AuthorizationCode
        {
            foreach ($this->s as $c) {
                if (hash_equals($c->hashedCode(), $h)) {
                    return $c;
                }
            } return null;
        }
        public function save(AuthorizationCode $c): void
        {
            $this->s[$c->id()] = $c;
        }
    };
    $access = new class () implements AccessTokenRepository {
        public array $s = [];
        private int $n = 0;
        public function nextIdentity(): string
        {
            return 'at'.(++$this->n);
        }
        public function findByHash(string $h): ?AccessToken
        {
            foreach ($this->s as $t) {
                if (hash_equals($t->hashedToken(), $h)) {
                    return $t;
                }
            } return null;
        }
        public function save(AccessToken $t): void
        {
            $this->s[$t->id()] = $t;
        }
    };
    $refresh = new class () implements RefreshTokenRepository {
        public array $s = [];
        private int $n = 0;
        public function nextIdentity(): string
        {
            return 'rt'.(++$this->n);
        }
        public function findByHash(string $h): ?RefreshToken
        {
            foreach ($this->s as $t) {
                if (hash_equals($t->hashedToken(), $h)) {
                    return $t;
                }
            } return null;
        }
        public function save(RefreshToken $t): void
        {
            $this->s[$t->id()] = $t;
        }
    };
    $hasher = new Sha256SecretHasher();
    $service = new OAuthService($clients, $access, $refresh, $codes, $hasher, [
        'access_ttl' => 3600, 'refresh_ttl' => 2592000, 'code_ttl' => 300,
        'access_prefix' => 'efoat_', 'refresh_prefix' => 'efort_', 'code_prefix' => 'efoac_',
    ]);

    $secret = 'secret-value';
    $clients->save(OAuthClient::register(
        'c-fixed',
        'app-1',
        'dev-1',
        'client',
        $hasher->hash($secret),
        true,
        [OAuthGrant::AuthorizationCode, OAuthGrant::ClientCredentials, OAuthGrant::RefreshToken],
        ['https://app.example/cb'],
        new ScopeSet(['orders:read', 'orders:write', 'foods:read']),
        new DateTimeImmutable(),
    ));

    return [$service, $secret];
}

it('runs the authorization_code + PKCE flow and enforces single use', function (): void {
    [$oauth, $secret] = makeOAuth();
    $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    $code = $oauth->issueAuthorizationCode('c-fixed', 'user-42', 'https://app.example/cb', new ScopeSet(['orders:read']), $challenge, 'S256');
    $issued = $oauth->exchangeAuthorizationCode('c-fixed', $secret, $code, 'https://app.example/cb', $verifier);

    expect($issued->accessToken)->toStartWith('efoat_')
        ->and($issued->refreshToken)->toStartWith('efort_')
        ->and($issued->scopes->toArray())->toBe(['orders:read']);

    expect(fn () => $oauth->exchangeAuthorizationCode('c-fixed', $secret, $code, 'https://app.example/cb', $verifier))
        ->toThrow(OAuthError::class);
});

it('rejects a bad PKCE verifier', function (): void {
    [$oauth, $secret] = makeOAuth();
    $verifier = 'the-real-verifier-value-1234567890';
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $code = $oauth->issueAuthorizationCode('c-fixed', 'u1', 'https://app.example/cb', new ScopeSet([]), $challenge, 'S256');

    expect(fn () => $oauth->exchangeAuthorizationCode('c-fixed', $secret, $code, 'https://app.example/cb', 'wrong'))
        ->toThrow(OAuthError::class);
});

it('issues client_credentials tokens with no subject and no refresh token', function (): void {
    [$oauth, $secret] = makeOAuth();
    $issued = $oauth->clientCredentials('c-fixed', $secret, new ScopeSet(['foods:read']));

    expect($issued->refreshToken)->toBeNull();
    $ctx = $oauth->authenticateAccessToken($issued->accessToken);
    expect($ctx)->not->toBeNull()
        ->and($ctx->subjectUserId)->toBeNull()
        ->and($ctx->authVia)->toBe('oauth2');
});

it('rejects a wrong client secret', function (): void {
    [$oauth] = makeOAuth();
    expect(fn () => $oauth->clientCredentials('c-fixed', 'wrong', new ScopeSet(['foods:read'])))
        ->toThrow(OAuthError::class);
});

it('rotates the refresh token and revokes the old one', function (): void {
    [$oauth, $secret] = makeOAuth();
    $verifier = 'verifier-value-abcdefghijklmnop';
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $code = $oauth->issueAuthorizationCode('c-fixed', 'u1', 'https://app.example/cb', new ScopeSet(['orders:read']), $challenge, 'S256');
    $issued = $oauth->exchangeAuthorizationCode('c-fixed', $secret, $code, 'https://app.example/cb', $verifier);

    $refreshed = $oauth->refresh('c-fixed', $secret, $issued->refreshToken, new ScopeSet([]));
    expect($refreshed->accessToken)->not->toBe($issued->accessToken);

    expect(fn () => $oauth->refresh('c-fixed', $secret, $issued->refreshToken, new ScopeSet([])))
        ->toThrow(OAuthError::class);
});

it('never widens a token beyond the client-allowed scopes', function (): void {
    [$oauth, $secret] = makeOAuth();
    $issued = $oauth->clientCredentials('c-fixed', $secret, new ScopeSet(['orders:read', 'nutrition:read']));
    $ctx = $oauth->authenticateAccessToken($issued->accessToken);
    expect($ctx->scopes->toArray())->toBe(['orders:read']);
});
