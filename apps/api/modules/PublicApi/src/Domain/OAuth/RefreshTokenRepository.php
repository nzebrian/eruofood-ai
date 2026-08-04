<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\OAuth;

/** Persistence port for refresh tokens. */
interface RefreshTokenRepository
{
    public function nextIdentity(): string;

    public function findByHash(string $hashedToken): ?RefreshToken;

    public function save(RefreshToken $token): void;
}
