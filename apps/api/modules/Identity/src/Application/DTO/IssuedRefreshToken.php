<?php

declare(strict_types=1);

namespace EruoFood\Identity\Application\DTO;

use DateTimeImmutable;

final readonly class IssuedRefreshToken
{
    public function __construct(
        public string $plaintext,
        public string $sessionId,
        public DateTimeImmutable $expiresAt,
    ) {
    }
}
