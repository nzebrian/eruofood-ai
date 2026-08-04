<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Application\Port;

/**
 * Hashes and verifies API key secrets. Plaintext secrets are never stored; only
 * the hash produced here is persisted, and verification is constant-time.
 */
interface SecretHasher
{
    public function hash(string $plaintext): string;

    public function verify(string $plaintext, string $hash): bool;
}
