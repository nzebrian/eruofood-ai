<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\ValueObject;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * Immutable UUID value object.
 *
 * Value objects are defined by their value, are immutable, and validate their
 * own invariants on construction. Identifiers across the platform are UUIDs
 * (UUIDv7 preferred for time-ordering — see MASTER_PLAN.md §5.1).
 */
final readonly class Uuid
{
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid UUID.', $value));
        }
    }

    public function equals(self $other): bool
    {
        return strtolower($this->value) === strtolower($other->value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
