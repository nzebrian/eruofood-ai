<?php

declare(strict_types=1);

namespace EruoFood\Identity\Application\DTO;

use DateTimeImmutable;

/**
 * One session, as its owner sees it.
 *
 * Deliberately coarse. It carries what somebody needs to recognise a session
 * and decide whether to end it — roughly where, roughly what, when it was last
 * active — and nothing that would help an attacker who obtained this list
 * impersonate the device. There is no token, no hash and no fingerprint here.
 */
final readonly class SessionView
{
    public function __construct(
        public string $sessionId,
        public ?string $ipAddress,
        public ?string $userAgent,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $lastUsedAt,
        public ?string $deviceId = null,
        public ?string $deviceName = null,
        public ?string $platform = null,
        public ?DateTimeImmutable $lastActivityAt = null,
        public bool $isCurrent = false,
    ) {
    }
}
