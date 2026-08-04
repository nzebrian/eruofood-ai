<?php

declare(strict_types=1);

namespace EruoFood\Identity\Application\Port;

use EruoFood\Identity\Domain\ValueObject\HashedPassword;

/** Hashes and verifies passwords. Implemented with Argon2id in infrastructure. */
interface PasswordHasher
{
    public function hash(string $plain): HashedPassword;

    public function verify(string $plain, HashedPassword $hash): bool;

    public function needsRehash(HashedPassword $hash): bool;
}
