<?php

declare(strict_types=1);

namespace EruoFood\Identity\Application\DTO;

/**
 * Device/context metadata captured with each session for security review.
 *
 * Every field is supplied by the client and none of it is trusted. A device id
 * is a *label*, not a credential: it lets a person recognise "my old phone" in
 * a session list so they can revoke it, and it lets us group a session family.
 * Nothing is authorised on the strength of it, and two clients claiming the
 * same device id is a nuisance rather than a vulnerability.
 */
final readonly class SessionMetadata
{
    public function __construct(
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
        public ?string $deviceId = null,
        public ?string $deviceName = null,
        public ?string $platform = null,
    ) {
    }
}
