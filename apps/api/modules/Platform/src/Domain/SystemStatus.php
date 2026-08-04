<?php

declare(strict_types=1);

namespace EruoFood\Platform\Domain;

use DateTimeImmutable;

/**
 * SystemStatus — a domain value object describing the platform's liveness.
 *
 * This is foundation/operational, not a business feature: it exists so the
 * scaffold demonstrates a full Clean Architecture vertical slice (Interface →
 * Application → Domain) and gives orchestrators a health signal.
 */
final readonly class SystemStatus
{
    public function __construct(
        public string $service,
        public string $version,
        public string $environment,
        public DateTimeImmutable $checkedAt,
        public bool $healthy = true,
    ) {
    }

    public function state(): string
    {
        return $this->healthy ? 'ok' : 'degraded';
    }
}
