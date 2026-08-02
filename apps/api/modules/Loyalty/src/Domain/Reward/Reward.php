<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Domain\Reward;

use DateTimeImmutable;
use EruoFood\Loyalty\Domain\Exception\LoyaltyInvalidState;

/**
 * A catalogue reward a member can redeem points for. It carries a points cost, a
 * benefit descriptor (a discount voucher, free delivery, a freebie — applied by
 * the consuming context, never here), optional stock, and an active flag with an
 * optional live window. The aggregate guards redeemability: a reward must be
 * active, in-window and in-stock to be redeemed.
 */
final class Reward
{
    private function __construct(
        private readonly string $id,
        private string $name,
        private string $description,
        private string $benefitType,
        private int $benefitValue,
        private int $pointsCost,
        private ?int $stock,
        private bool $active,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $startsAt,
        private ?DateTimeImmutable $endsAt,
    ) {
    }

    public static function create(
        string $id,
        string $name,
        string $description,
        string $benefitType,
        int $benefitValue,
        int $pointsCost,
        ?int $stock,
        DateTimeImmutable $now,
        ?DateTimeImmutable $startsAt = null,
        ?DateTimeImmutable $endsAt = null,
    ): self {
        if ($pointsCost <= 0) {
            throw new LoyaltyInvalidState('A reward must cost a positive number of points.');
        }

        return new self($id, $name, $description, $benefitType, $benefitValue, $pointsCost, $stock, true, $now, $startsAt, $endsAt);
    }

    /**
     * @param array{name?: string, description?: string, benefit_type?: string, benefit_value?: int, points_cost?: int, stock?: int|null, active?: bool, starts_at?: ?DateTimeImmutable, ends_at?: ?DateTimeImmutable} $changes
     */
    public function update(array $changes): void
    {
        $this->name = (string) ($changes['name'] ?? $this->name);
        $this->description = (string) ($changes['description'] ?? $this->description);
        $this->benefitType = (string) ($changes['benefit_type'] ?? $this->benefitType);
        $this->benefitValue = (int) ($changes['benefit_value'] ?? $this->benefitValue);
        $this->pointsCost = (int) ($changes['points_cost'] ?? $this->pointsCost);
        if (array_key_exists('stock', $changes)) {
            $this->stock = $changes['stock'];
        }
        if (array_key_exists('active', $changes)) {
            $this->active = (bool) $changes['active'];
        }
        if (array_key_exists('starts_at', $changes)) {
            $this->startsAt = $changes['starts_at'];
        }
        if (array_key_exists('ends_at', $changes)) {
            $this->endsAt = $changes['ends_at'];
        }
        if ($this->pointsCost <= 0) {
            throw new LoyaltyInvalidState('A reward must cost a positive number of points.');
        }
    }

    public function isRedeemableAt(DateTimeImmutable $now): bool
    {
        if (! $this->active) {
            return false;
        }
        if ($this->startsAt !== null && $now < $this->startsAt) {
            return false;
        }
        if ($this->endsAt !== null && $now > $this->endsAt) {
            return false;
        }

        return $this->stock === null || $this->stock > 0;
    }

    /** Decrement finite stock on a successful redemption. Unlimited stock is untouched. */
    public function consumeStock(): void
    {
        if ($this->stock !== null) {
            if ($this->stock <= 0) {
                throw new LoyaltyInvalidState('This reward is out of stock.');
            }
            $this->stock--;
        }
    }

    /** Return one unit of stock when a redemption is cancelled. */
    public function restock(): void
    {
        if ($this->stock !== null) {
            $this->stock++;
        }
    }

    public static function reconstitute(
        string $id,
        string $name,
        string $description,
        string $benefitType,
        int $benefitValue,
        int $pointsCost,
        ?int $stock,
        bool $active,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $startsAt,
        ?DateTimeImmutable $endsAt,
    ): self {
        return new self($id, $name, $description, $benefitType, $benefitValue, $pointsCost, $stock, $active, $createdAt, $startsAt, $endsAt);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function benefitType(): string
    {
        return $this->benefitType;
    }

    public function benefitValue(): int
    {
        return $this->benefitValue;
    }

    public function pointsCost(): int
    {
        return $this->pointsCost;
    }

    public function stock(): ?int
    {
        return $this->stock;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function startsAt(): ?DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function endsAt(): ?DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
