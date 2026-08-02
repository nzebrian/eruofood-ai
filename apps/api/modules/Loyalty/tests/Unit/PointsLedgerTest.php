<?php

declare(strict_types=1);

use EruoFood\Loyalty\Domain\Account\LoyaltyAccount;
use EruoFood\Loyalty\Domain\Account\Tier;
use EruoFood\Loyalty\Domain\Account\TierPolicy;
use EruoFood\Loyalty\Domain\Enum\LedgerEntryType;
use EruoFood\Loyalty\Domain\Exception\LoyaltyInvalidState;
use EruoFood\Loyalty\Domain\ValueObject\Points;

function account(): LoyaltyAccount
{
    return LoyaltyAccount::open('a1', 'user-1', 'bronze', new DateTimeImmutable());
}

it('rejects a non-positive points amount', function (): void {
    expect(fn () => new Points(0))->toThrow(LoyaltyInvalidState::class);
    expect(fn () => new Points(-1))->toThrow(LoyaltyInvalidState::class);
    expect((new Points(5))->value)->toBe(5);
});

it('grows balance and lifetime on earn, only balance on redeem', function (): void {
    $a = account();
    $a->earn(new Points(1000), 'order', null, 'e1', null, new DateTimeImmutable());
    expect($a->balance())->toBe(1000)->and($a->lifetimePoints())->toBe(1000);

    $entry = $a->redeem(new Points(400), 'redemption', 'rw1', 'e2', new DateTimeImmutable());
    expect($a->balance())->toBe(600)
        ->and($a->lifetimePoints())->toBe(1000)
        ->and($entry->points)->toBe(-400)
        ->and($entry->type)->toBe(LedgerEntryType::Redeem)
        ->and($entry->balanceAfter)->toBe(600);
});

it('never lets the balance go negative', function (): void {
    $a = account();
    $a->earn(new Points(100), 'order', null, 'e1', null, new DateTimeImmutable());
    expect(fn () => $a->redeem(new Points(500), 'x', null, 'e2', new DateTimeImmutable()))
        ->toThrow(LoyaltyInvalidState::class);
    expect(fn () => $a->adjust(-500, 'x', 'e3', new DateTimeImmutable()))
        ->toThrow(LoyaltyInvalidState::class);
});

it('expires points off the balance without touching lifetime', function (): void {
    $a = account();
    $a->earn(new Points(1000), 'order', null, 'e1', null, new DateTimeImmutable());
    $a->expire(300, 'e1', 'e2', new DateTimeImmutable());
    expect($a->balance())->toBe(700)->and($a->lifetimePoints())->toBe(1000);
});

it('flushes appended ledger entries exactly once', function (): void {
    $a = account();
    $a->earn(new Points(100), 'order', null, 'e1', null, new DateTimeImmutable());
    $a->earn(new Points(50), 'review', null, 'e2', null, new DateTimeImmutable());
    expect($a->releaseNewEntries())->toHaveCount(2)
        ->and($a->releaseNewEntries())->toHaveCount(0);
});

it('resolves the tier from lifetime points', function (): void {
    $policy = new TierPolicy([
        new Tier('bronze', 'Bronze', 0, 1.0),
        new Tier('silver', 'Silver', 1000, 1.1),
        new Tier('gold', 'Gold', 5000, 1.25),
    ]);
    expect($policy->resolve(0)->key)->toBe('bronze')
        ->and($policy->resolve(1000)->key)->toBe('silver')
        ->and($policy->resolve(9999)->key)->toBe('gold')
        ->and($policy->next(0)->key)->toBe('silver')
        ->and($policy->next(9999))->toBeNull();
});
