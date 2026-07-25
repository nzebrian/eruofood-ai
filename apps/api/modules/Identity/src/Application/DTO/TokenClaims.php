<?php

declare(strict_types=1);

namespace EruoFood\Identity\Application\DTO;

final readonly class TokenClaims
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        public string $userId,
        public array $roles,
    ) {
    }
}
