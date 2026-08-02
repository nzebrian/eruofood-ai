<?php

declare(strict_types=1);

use EruoFood\Loyalty\Domain\Enum\RedemptionStatus;
use EruoFood\Loyalty\Domain\Enum\ReferralStatus;
use EruoFood\Loyalty\Domain\Exception\LoyaltyConflict;
use EruoFood\Loyalty\Domain\Exception\LoyaltyInvalidState;
use EruoFood\Loyalty\Domain\Referral\Referral;
use EruoFood\Loyalty\Domain\Reward\Redemption;
use EruoFood\Loyalty\Domain\Reward\Reward;

it('guards reward redeemability by active flag, window and stock', function (): void {
    $now = new DateTimeImmutable('2027-02-10T10:00:00Z');
    $reward = Reward::create('rw1', 'X', 'x', 'discount', 100, 300, 1, $now);
    expect($reward->isRedeemableAt($now))->toBeTrue();

    $reward->consumeStock();
    expect($reward->stock())->toBe(0)->and($reward->isRedeemableAt($now))->toBeFalse();
    expect(fn () => $reward->consumeStock())->toThrow(LoyaltyInvalidState::class);

    $future = Reward::create('rw2', 'X', 'x', 'discount', 100, 300, null, $now, new DateTimeImmutable('2027-03-01T00:00:00Z'));
    expect($future->isRedeemableAt($now))->toBeFalse();
});

it('rejects creating a reward that costs no points', function (): void {
    expect(fn () => Reward::create('rw', 'X', 'x', 'discount', 100, 0, null, new DateTimeImmutable()))
        ->toThrow(LoyaltyInvalidState::class);
});

it('moves a redemption through its lifecycle once', function (): void {
    $now = new DateTimeImmutable();
    $r = Redemption::issue('rd1', 'rw1', 'user-1', 'EFR-ABC', 300, 'discount', 100, $now);
    expect($r->status())->toBe(RedemptionStatus::Issued);
    $r->fulfill($now);
    expect($r->status())->toBe(RedemptionStatus::Fulfilled);
    expect(fn () => $r->cancel($now))->toThrow(LoyaltyInvalidState::class);
});

it('rejects self-referral and qualifies a pending referral once', function (): void {
    $now = new DateTimeImmutable();
    expect(fn () => Referral::attribute('rf', 'C', 'u1', 'u1', $now))->toThrow(LoyaltyConflict::class);

    $ref = Referral::attribute('rf', 'C', 'u1', 'u2', $now);
    expect($ref->status())->toBe(ReferralStatus::Pending);
    $ref->qualify($now);
    expect($ref->status())->toBe(ReferralStatus::Qualified)
        ->and($ref->qualifiedAt())->not->toBeNull();
    expect(fn () => $ref->qualify($now))->toThrow(LoyaltyInvalidState::class);
});
