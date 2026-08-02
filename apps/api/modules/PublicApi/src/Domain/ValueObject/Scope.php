<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\ValueObject;

use EruoFood\PublicApi\Domain\Exception\PublicApiInvalidState;

/**
 * A single OAuth-style permission of the form `resource:action`
 * (e.g. `foods:read`, `orders:write`). Immutable and self-validating.
 */
final readonly class Scope
{
    public string $resource;

    public string $action;

    public function __construct(public string $value)
    {
        if (preg_match('/^[a-z][a-z0-9_]*:[a-z][a-z0-9_]*$/', $value) !== 1) {
            throw new PublicApiInvalidState(sprintf('Invalid scope "%s".', $value));
        }
        [$this->resource, $this->action] = explode(':', $value, 2);
    }

    public static function of(string $value): self
    {
        return new self($value);
    }

    public function equals(Scope $other): bool
    {
        return $this->value === $other->value;
    }
}
