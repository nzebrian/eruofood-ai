<?php

declare(strict_types=1);

namespace EruoFood\Identity\Application\Port;

/**
 * Issues and consumes single-use, expiring tokens keyed by a purpose + subject
 * (e.g. email verification for a user id, or password reset for an email).
 * Tokens are stored hashed with a TTL.
 */
interface OneTimeTokens
{
    /** Issue a token for ($purpose, $subject); returns the plaintext token. */
    public function issue(string $purpose, string $subject, int $ttlMinutes): string;

    /** Verify and consume a token. Returns true exactly once for a valid token. */
    public function consume(string $purpose, string $subject, string $token): bool;
}
