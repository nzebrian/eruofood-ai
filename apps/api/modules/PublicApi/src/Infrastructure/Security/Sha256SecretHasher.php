<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Infrastructure\Security;

use EruoFood\PublicApi\Application\Port\SecretHasher;

/**
 * Hashes API key secrets with SHA-256. The secrets are high-entropy random
 * tokens (not user passwords), so a fast cryptographic hash is appropriate and
 * lookup stays O(1); verification is constant-time via hash_equals.
 */
final class Sha256SecretHasher implements SecretHasher
{
    public function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public function verify(string $plaintext, string $hash): bool
    {
        return hash_equals($hash, hash('sha256', $plaintext));
    }
}
