<?php

declare(strict_types=1);

namespace EruoFood\Identity\Infrastructure\Auth\Social;

use EruoFood\Identity\Application\DTO\SocialIdentity;
use EruoFood\Identity\Application\Port\SocialAuthenticator;
use RuntimeException;

/**
 * Apple Sign-In — architecture-ready. The port is wired and the provider is
 * registered, but disabled by default (APPLE_AUTH_ENABLED=false). Completing it
 * means verifying Apple's identity token against Apple's JWKS and mapping the
 * claims; the surrounding login flow already handles the SocialIdentity it will
 * return, so enabling it is a localized change.
 */
final readonly class AppleAuthenticator implements SocialAuthenticator
{
    public function __construct(
        private bool $enabled,
    ) {
    }

    public function provider(): string
    {
        return 'apple';
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function verify(string $idToken): SocialIdentity
    {
        throw new RuntimeException('Apple Sign-In is not yet enabled. Set APPLE_AUTH_ENABLED and implement JWKS verification.');
    }
}
