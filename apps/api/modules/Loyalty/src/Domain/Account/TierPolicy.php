<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Domain\Account;

use EruoFood\Loyalty\Domain\Exception\LoyaltyInvalidState;

/**
 * Resolves a member's tier from their lifetime points. A pure function of the
 * configured tier ladder: a member sits in the highest tier whose threshold they
 * have reached. The single place tier logic lives, so the projector and any
 * read produce the same answer.
 */
final readonly class TierPolicy
{
    /** @var list<Tier> highest threshold last */
    private array $tiers;

    /**
     * @param list<Tier> $tiers
     */
    public function __construct(array $tiers)
    {
        if ($tiers === []) {
            throw new LoyaltyInvalidState('At least one tier must be configured.');
        }
        usort($tiers, static fn (Tier $a, Tier $b): int => $a->threshold <=> $b->threshold);
        $this->tiers = array_values($tiers);
    }

    public function resolve(int $lifetimePoints): Tier
    {
        $current = $this->tiers[0];
        foreach ($this->tiers as $tier) {
            if ($lifetimePoints >= $tier->threshold) {
                $current = $tier;
            }
        }

        return $current;
    }

    public function byKey(string $key): ?Tier
    {
        foreach ($this->tiers as $tier) {
            if ($tier->key === $key) {
                return $tier;
            }
        }

        return null;
    }

    /**
     * The next tier up from a lifetime-points total, or null at the top.
     */
    public function next(int $lifetimePoints): ?Tier
    {
        foreach ($this->tiers as $tier) {
            if ($tier->threshold > $lifetimePoints) {
                return $tier;
            }
        }

        return null;
    }

    /** @return list<Tier> */
    public function all(): array
    {
        return $this->tiers;
    }
}
