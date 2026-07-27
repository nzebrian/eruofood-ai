<?php

declare(strict_types=1);

use EruoFood\Commerce\Domain\Exception\CommerceInvalidState;
use EruoFood\Commerce\Domain\Inventory\InventoryItem;
use EruoFood\Commerce\Domain\ValueObject\Batch;

function stockItem(int $qty = 0, int $threshold = 10): InventoryItem
{
    return InventoryItem::open('inv1', 'p1', null, 'w1', 's1', $qty, $threshold);
}

it('receives stock and flags low stock at or below the threshold', function (): void {
    $item = stockItem(0, 10);
    $item->receive(10);
    expect($item->quantity())->toBe(10)->and($item->isLowStock())->toBeTrue();

    $item->receive(5);
    expect($item->quantity())->toBe(15)->and($item->isLowStock())->toBeFalse();
});

it('deducts stock and refuses to go negative', function (): void {
    $item = stockItem(5);
    $item->deduct(3);
    expect($item->quantity())->toBe(2);
    expect(fn () => $item->deduct(5))->toThrow(CommerceInvalidState::class);
});

it('tracks batches and consumes earliest-expiring first (FEFO)', function (): void {
    $item = stockItem(0, 0);
    $item->receive(5, new Batch('LATE', 5, new DateTimeImmutable('2026-12-01')));
    $item->receive(5, new Batch('SOON', 5, new DateTimeImmutable('2026-08-01')));
    expect($item->quantity())->toBe(10);
    expect($item->nearestExpiry()?->format('Y-m-d'))->toBe('2026-08-01');

    // Deduct 6 → the SOON batch (5) is emptied, LATE loses 1.
    $item->deduct(6);
    $numbers = array_map(static fn (Batch $b): string => $b->batchNumber, $item->batches());
    expect($numbers)->toBe(['LATE'])
        ->and($item->batches()[0]->quantity)->toBe(4);
});

it('reports batches expiring within a window', function (): void {
    $item = stockItem(0, 0);
    $asOf = new DateTimeImmutable('2026-07-27');
    $item->receive(3, new Batch('A', 3, new DateTimeImmutable('2026-08-05')));
    $item->receive(3, new Batch('B', 3, new DateTimeImmutable('2026-10-01')));
    expect($item->expiringBatches($asOf, 14))->toHaveCount(1);
});
