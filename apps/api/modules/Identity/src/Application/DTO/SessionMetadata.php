<?php

declare(strict_types=1);

namespace EruoFood\Identity\Application\DTO;

/** Device/context metadata captured with each session for security review. */
final readonly class SessionMetadata
{
    public function __construct(
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
    ) {
    }
}
