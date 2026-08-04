<?php

declare(strict_types=1);

use EruoFood\Analytics\Domain\Exception\AnalyticsInvalidState;
use EruoFood\Analytics\Domain\ValueObject\DateRange;

it('computes inclusive day counts', function (): void {
    $range = new DateRange(new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-07'));
    expect($range->days())->toBe(7)
        ->and($range->fromDate())->toBe('2026-09-01')
        ->and($range->toDate())->toBe('2026-09-07');
});

it('builds a last-N-days window', function (): void {
    $range = DateRange::lastDays(30, new DateTimeImmutable('2026-09-30T12:00:00'));
    expect($range->days())->toBe(30)
        ->and($range->fromDate())->toBe('2026-09-01')
        ->and($range->toDate())->toBe('2026-09-30');
});

it('rejects an inverted range', function (): void {
    expect(fn () => new DateRange(new DateTimeImmutable('2026-09-07'), new DateTimeImmutable('2026-09-01')))
        ->toThrow(AnalyticsInvalidState::class);
});
