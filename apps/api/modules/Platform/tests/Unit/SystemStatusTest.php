<?php

declare(strict_types=1);

use EruoFood\Platform\Domain\SystemStatus;

it('reports ok when healthy', function (): void {
    $status = new SystemStatus(
        service: 'EruoFood AI',
        version: '0.1.0',
        environment: 'testing',
        checkedAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
    );

    expect($status->state())->toBe('ok');
});

it('reports degraded when unhealthy', function (): void {
    $status = new SystemStatus(
        service: 'EruoFood AI',
        version: '0.1.0',
        environment: 'testing',
        checkedAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        healthy: false,
    );

    expect($status->state())->toBe('degraded');
});
