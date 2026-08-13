<?php

declare(strict_types=1);

use EruoFood\Loyalty\Application\Service\LoyaltyService;
use EruoFood\Loyalty\Application\Service\RedemptionService;
use EruoFood\Loyalty\Domain\Exception\LoyaltyInvalidState;
use EruoFood\Loyalty\Domain\Reward\Reward;
use EruoFood\Loyalty\Domain\Reward\RewardRepository;
use EruoFood\Loyalty\Infrastructure\Persistence\Eloquent\Model\AccountModel;
use EruoFood\Loyalty\Infrastructure\Persistence\Eloquent\Model\RedemptionModel;
use EruoFood\Loyalty\Infrastructure\Persistence\Eloquent\Model\RewardModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * M23 — points and reward stock are spendable value, so redeeming must be one
 * transaction with both rows locked.
 *
 * Previously the account, the reward and the redemption were each saved
 * separately: a failure between them could debit a member's points and issue no
 * voucher, or consume stock for a redemption that was never recorded.
 */
function seedReward(int $pointsCost, ?int $stock): string
{
    $rewards = app(RewardRepository::class);
    $reward = Reward::create(
        $rewards->nextIdentity(),
        'Free Delivery',
        'One free delivery',
        'delivery_discount',
        100_000,
        $pointsCost,
        $stock,
        new DateTimeImmutable(),
        null,
        null,
    );
    $rewards->save($reward);

    return $reward->id();
}

function seedMemberWithPoints(string $userId, int $points): void
{
    // adjust() credits an exact amount; earn() would apply the tier multiplier
    // and make the expected balances depend on tier configuration.
    app(LoyaltyService::class)->adjust($userId, $points, 'test-seed');
}

it('debits points, consumes stock and issues the voucher together', function (): void {
    $userId = (string) Str::uuid();
    seedMemberWithPoints($userId, 500);
    $rewardId = seedReward(200, 3);

    app(RedemptionService::class)->redeem($userId, $rewardId);

    expect((int) AccountModel::query()->where('user_id', $userId)->value('balance'))->toBe(300)
        ->and((int) RewardModel::query()->whereKey($rewardId)->value('stock'))->toBe(2)
        ->and(RedemptionModel::query()->count())->toBe(1);
});

it('leaves points and stock untouched when the redemption cannot be written', function (): void {
    $userId = (string) Str::uuid();
    seedMemberWithPoints($userId, 500);
    $rewardId = seedReward(200, 3);

    // Fail the last write in the sequence — the point at which the old code had
    // already committed both the points debit and the stock decrement.
    $failing = Mockery::mock(EruoFood\Loyalty\Domain\Reward\RedemptionRepository::class);
    $failing->shouldReceive('nextIdentity')->andReturn((string) Str::uuid());
    $failing->shouldReceive('nextCode')->andReturn('CODE-XYZ');
    $failing->shouldReceive('save')->andThrow(new RuntimeException('storage failed writing the redemption'));
    app()->instance(EruoFood\Loyalty\Domain\Reward\RedemptionRepository::class, $failing);

    expect(fn () => app(RedemptionService::class)->redeem($userId, $rewardId))
        ->toThrow(RuntimeException::class);

    expect((int) AccountModel::query()->where('user_id', $userId)->value('balance'))->toBe(500)
        ->and((int) RewardModel::query()->whereKey($rewardId)->value('stock'))->toBe(3);
});

it('refuses to redeem more points than the member holds', function (): void {
    $userId = (string) Str::uuid();
    seedMemberWithPoints($userId, 100);
    $rewardId = seedReward(500, 3);

    expect(fn () => app(RedemptionService::class)->redeem($userId, $rewardId))
        ->toThrow(LoyaltyInvalidState::class);

    expect((int) AccountModel::query()->where('user_id', $userId)->value('balance'))->toBe(100)
        ->and((int) RewardModel::query()->whereKey($rewardId)->value('stock'))->toBe(3);
});

it('refuses to redeem the last unit twice', function (): void {
    $first = (string) Str::uuid();
    $second = (string) Str::uuid();
    seedMemberWithPoints($first, 500);
    seedMemberWithPoints($second, 500);
    $rewardId = seedReward(200, 1);

    app(RedemptionService::class)->redeem($first, $rewardId);

    expect(fn () => app(RedemptionService::class)->redeem($second, $rewardId))
        ->toThrow(LoyaltyInvalidState::class);

    expect((int) RewardModel::query()->whereKey($rewardId)->value('stock'))->toBe(0)
        ->and(RedemptionModel::query()->count())->toBe(1)
        ->and((int) AccountModel::query()->where('user_id', $second)->value('balance'))->toBe(500);
});
