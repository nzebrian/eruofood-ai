<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\OAuth;

/** Persistence port for issued access tokens. */
interface AccessTokenRepository
{
    public function nextIdentity(): string;

    public function findByHash(string $hashedToken): ?AccessToken;

    public function save(AccessToken $token): void;
}
