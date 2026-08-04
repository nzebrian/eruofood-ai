<?php

declare(strict_types=1);

namespace EruoFood\Identity\Application\Port;

use EruoFood\Identity\Application\DTO\SocialIdentity;

/**
 * Verifies an OAuth/OIDC identity token from an external provider (Google,
 * Apple). Additional providers implement this same port, so the application
 * flow is provider-agnostic (Open/Closed Principle).
 */
interface SocialAuthenticator
{
    public function provider(): string;

    public function isEnabled(): bool;

    /** Verify the provider's id token and return the external identity. */
    public function verify(string $idToken): SocialIdentity;
}
