<?php

declare(strict_types=1);

use EruoFood\Analytics\Domain\Enum\Granularity;
use EruoFood\Analytics\Domain\Metric\Kpi;

it('computes a percentage delta versus the previous period', function (): void {
    $up = Kpi::withDelta('revenue', 'Revenue', 150, 100, 'money');
    expect($up->value)->toBe(150)->and($up->deltaPct)->toBe(50.0);

    $flat = Kpi::withDelta('orders', 'Orders', 10, 0, 'count');
    expect($flat->deltaPct)->toBeNull(); // no previous baseline
});

it('buckets dates by granularity', function (): void {
    $date = new DateTimeImmutable('2026-09-15');
    expect(Granularity::Day->bucketOf($date))->toBe('2026-09-15')
        ->and(Granularity::Month->bucketOf($date))->toBe('2026-09');
});
