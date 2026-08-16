<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Flag;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * Every flag the platform has, declared in one place.
 *
 * A flag that is not registered cannot be evaluated — {@see FlagEvaluator}
 * throws rather than guessing. That is deliberate: the failure mode of a
 * permissive lookup is a typo (`dispatch.egnine`) silently returning `false`
 * for ever, which looks exactly like a correctly-disabled feature and can hide
 * for months.
 */
final class FlagRegistry
{
    /** @var array<string, FeatureFlag> */
    private array $flags = [];

    public function register(FeatureFlag $flag): void
    {
        if (isset($this->flags[$flag->key])) {
            throw new InvalidArgumentException("Feature flag '{$flag->key}' is already registered.");
        }

        $this->flags[$flag->key] = $flag;
    }

    /** @throws InvalidArgumentException when the key was never registered */
    public function get(string $key): FeatureFlag
    {
        return $this->flags[$key]
            ?? throw new InvalidArgumentException(
                "Unknown feature flag '{$key}'. Flags must be registered before use.",
            );
    }

    public function has(string $key): bool
    {
        return isset($this->flags[$key]);
    }

    /** @return list<FeatureFlag> */
    public function all(): array
    {
        $flags = array_values($this->flags);

        usort($flags, static fn (FeatureFlag $a, FeatureFlag $b): int => $a->key <=> $b->key);

        return $flags;
    }
}
