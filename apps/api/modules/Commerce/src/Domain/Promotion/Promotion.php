<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Promotion;

use DateTimeImmutable;
use EruoFood\Commerce\Domain\Enum\PromotionType;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * A time-boxed price promotion applied to a set of products. When the window is
 * short and the aggregate is flagged as a flash sale, the storefront surfaces
 * it prominently. Applying a promotion never yields a negative price.
 */
final class Promotion
{
    /**
     * @param list<string> $productIds
     */
    private function __construct(
        private readonly string $id,
        private readonly ?string $storeId,
        private string $name,
        private PromotionType $type,
        private int $value,
        private array $productIds,
        private ?DateTimeImmutable $startsAt,
        private ?DateTimeImmutable $endsAt,
        private bool $flashSale,
    ) {
        if ($type === PromotionType::Percentage && ($value < 1 || $value > 100)) {
            throw new InvalidArgumentException('Percentage promotion must be 1-100.');
        }
        if ($value < 0) {
            throw new InvalidArgumentException('Promotion value cannot be negative.');
        }
    }

    /**
     * @param list<string> $productIds
     */
    public static function create(
        string $id,
        ?string $storeId,
        string $name,
        PromotionType $type,
        int $value,
        array $productIds,
        ?DateTimeImmutable $startsAt,
        ?DateTimeImmutable $endsAt,
        bool $flashSale = false,
    ): self {
        return new self(
            $id,
            $storeId,
            $name,
            $type,
            $value,
            array_values(array_unique($productIds)),
            $startsAt,
            $endsAt,
            $flashSale,
        );
    }

    /**
     * @param list<string> $productIds
     */
    public static function reconstitute(
        string $id,
        ?string $storeId,
        string $name,
        PromotionType $type,
        int $value,
        array $productIds,
        ?DateTimeImmutable $startsAt,
        ?DateTimeImmutable $endsAt,
        bool $flashSale,
    ): self {
        return new self(
            $id,
            $storeId,
            $name,
            $type,
            $value,
            $productIds,
            $startsAt,
            $endsAt,
            $flashSale,
        );
    }

    public function isActiveAt(DateTimeImmutable $now): bool
    {
        if ($this->startsAt !== null && $now < $this->startsAt) {
            return false;
        }
        if ($this->endsAt !== null && $now > $this->endsAt) {
            return false;
        }

        return true;
    }

    public function appliesTo(string $productId): bool
    {
        return in_array($productId, $this->productIds, true);
    }

    /** Apply the discount to a price, flooring at zero. */
    public function applyTo(Money $price): Money
    {
        $off = $this->type === PromotionType::Percentage
            ? (int) round($price->minorUnits * $this->value / 100)
            : $this->value;

        return new Money(max(0, $price->minorUnits - $off), $price->currency);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function storeId(): ?string
    {
        return $this->storeId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): PromotionType
    {
        return $this->type;
    }

    public function value(): int
    {
        return $this->value;
    }

    /** @return list<string> */
    public function productIds(): array
    {
        return $this->productIds;
    }

    public function startsAt(): ?DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function endsAt(): ?DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function isFlashSale(): bool
    {
        return $this->flashSale;
    }
}
