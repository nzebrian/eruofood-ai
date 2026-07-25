<?php

declare(strict_types=1);

namespace EruoFood\Identity\Application\DTO;

final readonly class AccessToken
{
    public function __construct(
        public string $value,
        public int $expiresInSeconds,
    ) {
    }
}
