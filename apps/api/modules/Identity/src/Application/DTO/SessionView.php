<?php

declare(strict_types=1);

namespace EruoFood\Identity\Application\DTO;

use DateTimeImmutable;

final readonly class SessionView
{
    public function __construct(
        public string $sessionId,
        public ?string $ipAddress,
        public ?string $userAgent,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $lastUsedAt,
    ) {
    }
}
