<?php

declare(strict_types=1);

namespace EruoFood\Identity\Domain\ValueObject;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * Phone number in E.164 form (e.g. +2348012345678). Kept generic so it serves
 * the (architecture-ready) phone authentication flow across regions.
 */
final readonly class PhoneNumber
{
    public string $value;

    public function __construct(string $value)
    {
        $normalized = preg_replace('/[\s\-()]/', '', $value) ?? '';

        if (preg_match('/^\+[1-9]\d{7,14}$/', $normalized) !== 1) {
            throw new InvalidArgumentException('Phone number must be in E.164 format, e.g. +2348012345678.');
        }

        $this->value = $normalized;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
