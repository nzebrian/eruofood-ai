<?php

declare(strict_types=1);

namespace EruoFood\Identity\Application\DTO;

/** The token bundle returned on successful authentication. */
final readonly class AuthTokens
{
    public function __construct(
        public string $accessToken,
        public int $expiresIn,
        public string $refreshToken,
        public string $tokenType = 'Bearer',
    ) {
    }
}
