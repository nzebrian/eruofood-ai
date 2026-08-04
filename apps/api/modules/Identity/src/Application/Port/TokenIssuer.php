<?php

declare(strict_types=1);

namespace EruoFood\Identity\Application\Port;

use EruoFood\Identity\Application\DTO\AccessToken;
use EruoFood\Identity\Application\DTO\TokenClaims;
use EruoFood\Identity\Domain\User\User;

/**
 * Issues and parses stateless access tokens (JWT). Implemented with a signed
 * JWT in infrastructure; the domain/application never sees the signing details.
 */
interface TokenIssuer
{
    public function issue(User $user): AccessToken;

    /** Returns the verified claims, or null if the token is invalid/expired. */
    public function parse(string $token): ?TokenClaims;
}
