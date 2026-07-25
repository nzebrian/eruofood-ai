<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain;

use DateTimeImmutable;

/**
 * Port abstracting "the current time".
 *
 * Depending on a Clock instead of calling now() directly keeps the domain
 * deterministic and testable (a fake clock can be injected in tests).
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
