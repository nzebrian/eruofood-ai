<?php

declare(strict_types=1);

namespace EruoFood\Identity\Infrastructure\Auth;

use EruoFood\Identity\Application\Port\PasswordHasher;
use EruoFood\Identity\Domain\ValueObject\HashedPassword;

/**
 * Argon2id password hasher (MASTER_PLAN.md §7.2). Uses PHP's native password_*
 * functions so hashing parameters are self-describing in the stored hash.
 */
final class Argon2PasswordHasher implements PasswordHasher
{
    private const ALGO = PASSWORD_ARGON2ID;

    /** @var array<string, int> */
    private const OPTIONS = [
        'memory_cost' => 65536,
        'time_cost' => 4,
        'threads' => 2,
    ];

    public function hash(string $plain): HashedPassword
    {
        return new HashedPassword(password_hash($plain, self::ALGO, self::OPTIONS));
    }

    public function verify(string $plain, HashedPassword $hash): bool
    {
        return password_verify($plain, $hash->value);
    }

    public function needsRehash(HashedPassword $hash): bool
    {
        return password_needs_rehash($hash->value, self::ALGO, self::OPTIONS);
    }
}
