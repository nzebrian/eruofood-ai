<?php

declare(strict_types=1);

namespace EruoFood\Ai\Tests\Support;

use DateTimeImmutable;
use EruoFood\Shared\Domain\Clock;

/** A fixed clock for deterministic tests. */
final class FakeClock implements Clock
{
    public function __construct(private readonly string $at = '2026-01-01T00:00:00Z')
    {
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->at);
    }
}
