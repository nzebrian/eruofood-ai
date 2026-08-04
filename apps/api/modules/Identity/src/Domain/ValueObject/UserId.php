<?php

declare(strict_types=1);

namespace EruoFood\Identity\Domain\ValueObject;

use EruoFood\Shared\Domain\ValueObject\Uuid;

/**
 * Strongly-typed identifier for a User. Wrapping the shared Uuid gives us type
 * safety (a UserId can never be confused with another entity's id).
 */
final readonly class UserId
{
    private Uuid $uuid;

    public function __construct(string $value)
    {
        $this->uuid = new Uuid($value);
    }

    public function value(): string
    {
        return $this->uuid->value;
    }

    public function equals(self $other): bool
    {
        return $this->uuid->equals($other->uuid);
    }

    public function __toString(): string
    {
        return $this->uuid->value;
    }
}
