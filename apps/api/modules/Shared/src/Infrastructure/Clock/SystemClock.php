<?php

declare(strict_types=1);

namespace EruoFood\Shared\Infrastructure\Clock;

use DateTimeImmutable;
use DateTimeZone;
use EruoFood\Shared\Domain\Clock;

/**
 * Real system clock — the production implementation of the Clock port.
 *
 * ## Why the timezone is hard-coded rather than read from config
 *
 * This used to be `new DateTimeImmutable('now')`, which takes PHP's default
 * timezone — in practice whatever `app.timezone` happens to say. That made the
 * meaning of every stored timestamp a configuration value, and the platform
 * shipped with it set to `Africa/Lagos`, so the database held local wall-clock
 * while PostgreSQL itself was running in UTC.
 *
 * Naming UTC here makes the guarantee structural. A future edit to
 * `config/app.php`, a differently-configured worker container, or a deployment
 * in a second country cannot change what an authoritative timestamp means.
 * Display timezones are a presentation concern and belong at the edge; see
 * {@see \EruoFood\Shared\Domain\Time\WallClock}.
 */
final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
