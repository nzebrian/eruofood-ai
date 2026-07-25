<?php

declare(strict_types=1);

namespace EruoFood\Identity\Application\Service;

use EruoFood\Identity\Application\DTO\AccessToken;
use EruoFood\Identity\Application\DTO\AuthTokens;
use EruoFood\Identity\Application\DTO\SessionMetadata;
use EruoFood\Identity\Application\Port\RefreshTokenManager;
use EruoFood\Identity\Application\Port\TokenIssuer;
use EruoFood\Identity\Domain\User\User;

/**
 * Builds the access + refresh token bundle for an authenticated user. Shared by
 * registration and every login path so token issuance lives in one place (DRY).
 */
final readonly class TokenService
{
    public function __construct(
        private TokenIssuer $tokenIssuer,
        private RefreshTokenManager $refreshTokens,
    ) {
    }

    public function issueFor(User $user, SessionMetadata $meta): AuthTokens
    {
        $access = $this->tokenIssuer->issue($user);
        $refresh = $this->refreshTokens->issue($user->id(), $meta);

        return new AuthTokens(
            accessToken: $access->value,
            expiresIn: $access->expiresInSeconds,
            refreshToken: $refresh->plaintext,
        );
    }

    /** Mint only a fresh access token (used when rotating a refresh token). */
    public function accessToken(User $user): AccessToken
    {
        return $this->tokenIssuer->issue($user);
    }
}
