<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Application\Service;

use DateTimeImmutable;
use EruoFood\Loyalty\Domain\Exception\LoyaltyNotFound;
use EruoFood\Loyalty\Domain\Reward\Reward;
use EruoFood\Loyalty\Domain\Reward\RewardRepository;
use EruoFood\Shared\Domain\Paginated;

/**
 * The rewards catalogue: the customer-facing list of currently-redeemable
 * rewards and the admin create/update surface. Redeeming a reward lives in
 * {@see RedemptionService}; this service only manages the catalogue itself.
 */
final readonly class RewardService
{
    public function __construct(private RewardRepository $rewards)
    {
    }

    /**
     * @return Paginated<Reward>
     */
    public function catalogue(bool $activeOnly, int $page, int $perPage): Paginated
    {
        return $this->rewards->catalogue($activeOnly, $page, $perPage);
    }

    public function get(string $id): Reward
    {
        return $this->rewards->findById($id) ?? throw LoyaltyNotFound::of('reward', $id);
    }

    public function create(
        string $name,
        string $description,
        string $benefitType,
        int $benefitValue,
        int $pointsCost,
        ?int $stock,
        ?DateTimeImmutable $startsAt,
        ?DateTimeImmutable $endsAt,
    ): Reward {
        $reward = Reward::create(
            $this->rewards->nextIdentity(),
            $name,
            $description,
            $benefitType,
            $benefitValue,
            $pointsCost,
            $stock,
            new DateTimeImmutable(),
            $startsAt,
            $endsAt,
        );
        $this->rewards->save($reward);

        return $reward;
    }

    /**
     * @param array<string, mixed> $changes
     */
    public function update(string $id, array $changes): Reward
    {
        $reward = $this->get($id);
        $reward->update($changes);
        $this->rewards->save($reward);

        return $reward;
    }
}
