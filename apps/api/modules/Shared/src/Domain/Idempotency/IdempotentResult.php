<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain\Idempotency;

/**
 * The outcome of an idempotent operation, and whether this call did the work or
 * received the stored answer from an earlier identical call.
 *
 * Callers use {@see $replayed} to choose a status code — a fresh creation is
 * 201, a replay of that same creation is 200 — without re-running any side
 * effect.
 */
final readonly class IdempotentResult
{
    /** @param array<string, mixed> $value */
    private function __construct(
        public array $value,
        public bool $replayed,
    ) {
    }

    /** @param array<string, mixed> $value */
    public static function fresh(array $value): self
    {
        return new self($value, false);
    }

    /** @param array<string, mixed> $value */
    public static function replayed(array $value): self
    {
        return new self($value, true);
    }
}
