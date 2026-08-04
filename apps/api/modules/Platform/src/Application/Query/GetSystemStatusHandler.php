<?php

declare(strict_types=1);

namespace EruoFood\Platform\Application\Query;

use EruoFood\Platform\Domain\SystemStatus;
use EruoFood\Shared\Domain\Clock;

/**
 * Handles GetSystemStatus. Orchestrates the domain and returns a read model.
 *
 * Dependencies (Clock, config values) are injected — the handler never reaches
 * out to global state, satisfying the Dependency Inversion Principle and
 * keeping the use case unit-testable.
 */
final readonly class GetSystemStatusHandler
{
    public function __construct(
        private Clock $clock,
        private string $service,
        private string $version,
        private string $environment,
    ) {
    }

    public function __invoke(GetSystemStatus $query): SystemStatus
    {
        return new SystemStatus(
            service: $this->service,
            version: $this->version,
            environment: $this->environment,
            checkedAt: $this->clock->now(),
        );
    }
}
