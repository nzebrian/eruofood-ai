<?php

declare(strict_types=1);

namespace EruoFood\Identity\Domain\ValueObject;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * An already-hashed password. The domain never stores or handles plaintext;
 * hashing/verification is delegated to the PasswordHasher port. This VO simply
 * guarantees the stored value looks like a real hash.
 */
final readonly class HashedPassword
{
    public string $value;

    public function __construct(string $value)
    {
        if ($value === '' || mb_strlen($value) < 20) {
            throw new InvalidArgumentException('Invalid password hash.');
        }

        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
