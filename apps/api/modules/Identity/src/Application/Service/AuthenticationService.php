<?php

declare(strict_types=1);

namespace EruoFood\Identity\Application\Service;

use EruoFood\Identity\Application\DTO\AuthResult;
use EruoFood\Identity\Application\DTO\AuthTokens;
use EruoFood\Identity\Application\DTO\SessionMetadata;
use EruoFood\Identity\Application\Port\AuditRecorder;
use EruoFood\Identity\Application\Port\LoginChallenges;
use EruoFood\Identity\Application\Port\OAuthAccounts;
use EruoFood\Identity\Application\Port\PasswordHasher;
use EruoFood\Identity\Application\Port\RefreshTokenManager;
use EruoFood\Identity\Application\Port\SocialAuthenticator;
use EruoFood\Identity\Application\Port\TwoFactorAuthenticator;
use EruoFood\Identity\Domain\Exception\AccountSuspended;
use EruoFood\Identity\Domain\Exception\InvalidCredentials;
use EruoFood\Identity\Domain\Exception\InvalidTwoFactorCode;
use EruoFood\Identity\Domain\User\User;
use EruoFood\Identity\Domain\User\UserRepository;
use EruoFood\Identity\Domain\ValueObject\Email;
use EruoFood\Identity\Domain\ValueObject\FullName;

/**
 * Authentication use cases: email/password login (with optional 2FA), social
 * sign-in, token refresh, and logout. Orchestrates domain + ports; contains no
 * framework or persistence detail.
 */
final readonly class AuthenticationService
{
    /**
     * @param array<string, SocialAuthenticator> $socialProviders keyed by provider name
     */
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $hasher,
        private TwoFactorAuthenticator $twoFactor,
        private LoginChallenges $challenges,
        private RefreshTokenManager $refreshTokens,
        private OAuthAccounts $oauthAccounts,
        private TokenService $tokenService,
        private UserPresenter $presenter,
        private AuditRecorder $audit,
        private array $socialProviders,
    ) {
    }

    public function loginWithPassword(string $email, string $password, SessionMetadata $meta): AuthResult
    {
        $user = $this->users->findByEmail(new Email($email));

        if ($user === null || $user->password() === null
            || ! $this->hasher->verify($password, $user->password())) {
            $this->audit->record('auth.login_failed', $user?->id(), ['email' => $email]);
            throw new InvalidCredentials();
        }

        $this->assertNotSuspended($user);

        // If 2FA is enabled, defer token issuance behind a challenge.
        if ($user->twoFactor()->isEnabled()) {
            $challengeToken = $this->challenges->create($user->id());

            return AuthResult::twoFactorChallenge($challengeToken);
        }

        return $this->completeLogin($user, $meta, 'password');
    }

    public function completeTwoFactorLogin(string $challengeToken, string $code, SessionMetadata $meta): AuthResult
    {
        $userId = $this->challenges->resolve($challengeToken) ?? throw new InvalidTwoFactorCode();
        $user = $this->users->findById($userId) ?? throw new InvalidTwoFactorCode();

        $secret = $user->twoFactor()->secret;
        if ($secret === null || ! $this->twoFactor->verify($secret, $code)) {
            throw new InvalidTwoFactorCode();
        }

        $this->challenges->forget($challengeToken);

        return $this->completeLogin($user, $meta, 'password+2fa');
    }

    public function loginWithSocial(string $provider, string $idToken, SessionMetadata $meta): AuthResult
    {
        $authenticator = $this->socialProviders[$provider] ?? throw new InvalidCredentials();
        if (! $authenticator->isEnabled()) {
            throw new InvalidCredentials();
        }

        $identity = $authenticator->verify($idToken);

        // Resolve the local user: by linked provider id, then by email, else create.
        $userId = $this->oauthAccounts->findUserIdByProvider($provider, $identity->providerUserId);
        $user = $userId !== null ? $this->users->findById($userId) : null;

        if ($user === null) {
            $user = $this->users->findByEmail(new Email($identity->email));

            if ($user === null) {
                $user = User::register(
                    id: $this->users->nextIdentity(),
                    name: new FullName($identity->name ?? $identity->email),
                    email: new Email($identity->email),
                    password: null,
                );
                if ($identity->emailVerified) {
                    $user->verifyEmail();
                }
                $this->users->save($user);
            }

            $this->oauthAccounts->link($user->id(), $provider, $identity->providerUserId);
        }

        $this->assertNotSuspended($user);

        return $this->completeLogin($user, $meta, $provider);
    }

    public function refresh(string $refreshToken, SessionMetadata $meta): AuthTokens
    {
        $userId = $this->refreshTokens->resolveUser($refreshToken) ?? throw new InvalidCredentials();
        $user = $this->users->findById($userId) ?? throw new InvalidCredentials();
        $this->assertNotSuspended($user);

        $rotated = $this->refreshTokens->rotate($refreshToken, $meta) ?? throw new InvalidCredentials();
        $access = $this->tokenService->accessToken($user);

        return new AuthTokens($access->value, $access->expiresInSeconds, $rotated->plaintext);
    }

    public function logout(string $refreshToken): void
    {
        $this->refreshTokens->revoke($refreshToken);
    }

    private function completeLogin(User $user, SessionMetadata $meta, string $method): AuthResult
    {
        $tokens = $this->tokenService->issueFor($user, $meta);
        $this->audit->record('auth.login', $user->id(), ['method' => $method, 'ip' => $meta->ipAddress]);

        return AuthResult::authenticated($this->presenter->present($user), $tokens);
    }

    private function assertNotSuspended(User $user): void
    {
        if (! $user->status()->isActive()) {
            throw new AccountSuspended();
        }
    }
}
