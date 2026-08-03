<?php

declare(strict_types=1);

/**
 * Milestone 18 — DB-backed OAuth2 security validation. Unlike the unit tests
 * (in-memory repositories), this boots the Laravel container, migrates a real
 * database, seeds an OAuth client through the Eloquent repository, and drives
 * the OAuthService end-to-end against persisted tokens/codes. It asserts the
 * security-relevant behaviours: PKCE, single-use codes, refresh rotation, scope
 * containment, client-secret verification, token expiry, revocation, client
 * isolation, and that client-credentials tokens carry no BOLA subject.
 *
 * Run: php scripts/oauth_db_validation.php   (uses sqlite :memory: by default)
 */

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use EruoFood\PublicApi\Application\Port\SecretHasher;
use EruoFood\PublicApi\Application\Service\OAuthService;
use EruoFood\PublicApi\Domain\Enum\OAuthGrant;
use EruoFood\PublicApi\Domain\Exception\OAuthError;
use EruoFood\PublicApi\Domain\OAuth\AccessToken;
use EruoFood\PublicApi\Domain\OAuth\AccessTokenRepository;
use EruoFood\PublicApi\Domain\OAuth\OAuthClient;
use EruoFood\PublicApi\Domain\OAuth\OAuthClientRepository;
use EruoFood\PublicApi\Domain\ValueObject\ScopeSet;
use Illuminate\Support\Facades\Artisan;

config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
Artisan::call('migrate', ['--force' => true]);

$pass = 0;
$fail = 0;
function check(string $label, bool $cond, string $extra = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ok  — {$label}".($extra !== '' ? "  ({$extra})" : '')."\n";
    } else {
        $fail++;
        echo "  FAIL — {$label}".($extra !== '' ? "  ({$extra})" : '')."\n";
    }
}

/** @var OAuthService $oauth */
$oauth = app(OAuthService::class);
/** @var OAuthClientRepository $clients */
$clients = app(OAuthClientRepository::class);
/** @var AccessTokenRepository $accessTokens */
$accessTokens = app(AccessTokenRepository::class);
/** @var SecretHasher $hasher */
$hasher = app(SecretHasher::class);

echo "DB-backed OAuth2 — persisted via Eloquent (sqlite :memory:)\n\n";

// Seed a confidential client supporting all three grants.
$secret = 'super-secret-value';
$clientId = $clients->nextIdentity();
$clients->save(OAuthClient::register(
    $clientId,
    'app-1',
    'dev-1',
    'Test client',
    $hasher->hash($secret),
    true,
    [OAuthGrant::AuthorizationCode, OAuthGrant::ClientCredentials, OAuthGrant::RefreshToken],
    ['https://app.example/cb'],
    new ScopeSet(['orders:read', 'orders:write', 'foods:read']),
    new DateTimeImmutable(),
));
// A second, isolated client.
$otherId = $clients->nextIdentity();
$clients->save(OAuthClient::register(
    $otherId,
    'app-2',
    'dev-2',
    'Other client',
    $hasher->hash('other-secret'),
    true,
    [OAuthGrant::RefreshToken],
    ['https://other.example/cb'],
    new ScopeSet(['foods:read']),
    new DateTimeImmutable(),
));

echo "1) Authorization Code + PKCE (persisted code, single use):\n";
$verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
$code = $oauth->issueAuthorizationCode($clientId, 'user-42', 'https://app.example/cb', new ScopeSet(['orders:read']), $challenge, 'S256');
try {
    $oauth->exchangeAuthorizationCode($clientId, $secret, $code, 'https://app.example/cb', 'wrong-verifier');
    check('PKCE bypass attempt rejected', false);
} catch (OAuthError $e) {
    check('PKCE bypass attempt rejected', $e->oauthError() === 'invalid_grant');
}
$issued = $oauth->exchangeAuthorizationCode($clientId, $secret, $code, 'https://app.example/cb', $verifier);
check('valid exchange yields access + refresh token', $issued->accessToken !== '' && $issued->refreshToken !== null);
check('scope narrowed to the requested subset', $issued->scopes->toArray() === ['orders:read']);
try {
    $oauth->exchangeAuthorizationCode($clientId, $secret, $code, 'https://app.example/cb', $verifier);
    check('authorization code cannot be replayed', false);
} catch (OAuthError $e) {
    check('authorization code cannot be replayed', $e->oauthError() === 'invalid_grant');
}

echo "\n2) redirect_uri validation:\n";
$code2 = $oauth->issueAuthorizationCode($clientId, 'user-42', 'https://app.example/cb', new ScopeSet(['orders:read']), $challenge, 'S256');
try {
    $oauth->exchangeAuthorizationCode($clientId, $secret, $code2, 'https://evil.example/cb', $verifier);
    check('mismatched redirect_uri rejected', false);
} catch (OAuthError $e) {
    check('mismatched redirect_uri rejected', $e->oauthError() === 'invalid_grant');
}
try {
    $oauth->issueAuthorizationCode($clientId, 'user-42', 'https://unregistered.example/cb', new ScopeSet(['orders:read']), $challenge, 'S256');
    check('unregistered redirect_uri refused at authorize', false);
} catch (OAuthError $e) {
    check('unregistered redirect_uri refused at authorize', $e->oauthError() === 'invalid_request');
}

echo "\n3) Token introspection persists the subject (BOLA source of truth):\n";
$ctx = $oauth->authenticateAccessToken($issued->accessToken);
check('access token introspects to a context', $ctx !== null);
check('context carries the delegated subject user', $ctx !== null && $ctx->subjectUserId === 'user-42');
check('context authVia = oauth2', $ctx !== null && $ctx->authVia === 'oauth2');

echo "\n4) Refresh rotation + reuse detection:\n";
$refreshed = $oauth->refresh($clientId, $secret, $issued->refreshToken, new ScopeSet([]));
check('refresh issues a new access token', $refreshed->accessToken !== $issued->accessToken);
try {
    $oauth->refresh($clientId, $secret, $issued->refreshToken, new ScopeSet([]));
    check('rotated (old) refresh token is revoked', false);
} catch (OAuthError $e) {
    check('rotated (old) refresh token is revoked', $e->oauthError() === 'invalid_grant');
}

echo "\n5) Client Credentials — no subject, no refresh, secret enforced:\n";
$cc = $oauth->clientCredentials($clientId, $secret, new ScopeSet(['foods:read']));
check('client_credentials token has no refresh token', $cc->refreshToken === null);
$ccCtx = $oauth->authenticateAccessToken($cc->accessToken);
check('client_credentials token has NO subject (cannot reach customer data)', $ccCtx !== null && $ccCtx->subjectUserId === null);
try {
    $oauth->clientCredentials($clientId, 'wrong-secret', new ScopeSet(['foods:read']));
    check('wrong client secret rejected (invalid_client)', false);
} catch (OAuthError $e) {
    check('wrong client secret rejected (invalid_client)', $e->oauthError() === 'invalid_client');
}

echo "\n6) Scope escalation blocked:\n";
$narrow = $oauth->clientCredentials($clientId, $secret, new ScopeSet(['orders:read', 'nutrition:read', 'admin:write']));
$narrowCtx = $oauth->authenticateAccessToken($narrow->accessToken);
check('token never widens beyond client-allowed scopes', $narrowCtx !== null && $narrowCtx->scopes->toArray() === ['orders:read']);

echo "\n7) Client isolation — one client cannot use another's refresh token:\n";
try {
    $oauth->refresh($otherId, 'other-secret', $refreshed->refreshToken, new ScopeSet([]));
    check('cross-client refresh token rejected', false);
} catch (OAuthError $e) {
    check('cross-client refresh token rejected', $e->oauthError() === 'invalid_grant');
}

echo "\n8) Token expiry + revocation (persisted state):\n";
// Forge an already-expired token directly in the store and confirm it is rejected.
$expiredPlain = 'efoat_expired_'.bin2hex(random_bytes(8));
$accessTokens->save(AccessToken::issue(
    $accessTokens->nextIdentity(),
    $hasher->hash($expiredPlain),
    $clientId,
    'app-1',
    'dev-1',
    'user-42',
    new ScopeSet(['orders:read']),
    (new DateTimeImmutable())->modify('-1 hour'),
    new DateTimeImmutable(),
));
check('an expired access token is rejected', $oauth->authenticateAccessToken($expiredPlain) === null);
// Revoke a live token.
$liveTok = $accessTokens->findByHash($hasher->hash($cc->accessToken));
$liveTok->revoke(new DateTimeImmutable());
$accessTokens->save($liveTok);
check('a revoked access token is rejected', $oauth->authenticateAccessToken($cc->accessToken) === null);

echo "\n== {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);
