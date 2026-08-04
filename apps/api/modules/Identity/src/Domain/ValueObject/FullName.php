<?php

declare(strict_types=1);

namespace EruoFood\Identity\Domain\ValueObject;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * A person's display name. Trimmed and length-validated.
 */
final readonly class FullName
{
    public string $value;

    public function __construct(string $value)
    {
        $trimmed = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        if (mb_strlen($trimmed) < 2 || mb_strlen($trimmed) > 120) {
            throw new InvalidArgumentException('Name must be between 2 and 120 characters.');
        }

        $this->value = $trimmed;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
