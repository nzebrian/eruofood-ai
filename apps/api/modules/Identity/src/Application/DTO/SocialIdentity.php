<?php

declare(strict_types=1);

namespace EruoFood\Identity\Application\DTO;

/** Normalised identity returned by a SocialAuthenticator. */
final readonly class SocialIdentity
{
    public function __construct(
        public string $provider,
        public string $providerUserId,
        public string $email,
        public ?string $name,
        public bool $emailVerified,
    ) {
    }
}
