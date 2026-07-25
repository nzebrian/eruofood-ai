<?php

declare(strict_types=1);

namespace EruoFood\Shared\Infrastructure\Clock;

use DateTimeImmutable;
use EruoFood\Shared\Domain\Clock;

/**
 * Real system clock — the production implementation of the Clock port.
 */
final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }
}
