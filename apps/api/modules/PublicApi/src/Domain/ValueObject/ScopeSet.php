<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\ValueObject;

/**
 * An immutable set of granted {@see Scope}s. It is the authority for "external
 * clients receive only explicitly granted permissions": an API key's scope set
 * is the intersection of what its application was granted and what the key
 * requested, and {@see grants()} is the single check the gateway enforces.
 */
final readonly class ScopeSet
{
    /** @var list<string> normalised, unique, sorted scope strings */
    public array $values;

    /**
     * @param list<string> $values
     */
    public function __construct(array $values)
    {
        $normalised = [];
        foreach ($values as $v) {
            $scope = new Scope((string) $v); // validates format
            $normalised[$scope->value] = true;
        }
        $keys = array_keys($normalised);
        sort($keys);
        $this->values = array_values($keys);
    }

    public static function fromArray(mixed $values): self
    {
        return new self(is_array($values) ? array_values(array_map('strval', $values)) : []);
    }

    public function grants(Scope $scope): bool
    {
        return in_array($scope->value, $this->values, true);
    }

    public function grantsAll(ScopeSet $required): bool
    {
        foreach ($required->values as $v) {
            if (! in_array($v, $this->values, true)) {
                return false;
            }
        }

        return true;
    }

    /** The subset of $this that also appears in $allowed — never widens beyond $allowed. */
    public function intersect(ScopeSet $allowed): self
    {
        return new self(array_values(array_intersect($this->values, $allowed->values)));
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /** @return list<string> */
    public function toArray(): array
    {
        return $this->values;
    }
}
