<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Shopping;

/**
 * A user's wishlist — a set of saved product ids (order preserved, no
 * duplicates). Simple by design; quantities and pricing belong to the cart.
 */
final class Wishlist
{
    /**
     * @param list<string> $productIds
     */
    private function __construct(
        private readonly string $userId,
        private array $productIds,
    ) {
    }

    public static function forUser(string $userId): self
    {
        return new self($userId, []);
    }

    /**
     * @param list<string> $productIds
     */
    public static function reconstitute(string $userId, array $productIds): self
    {
        return new self($userId, array_values(array_unique($productIds)));
    }

    public function add(string $productId): void
    {
        if (! in_array($productId, $this->productIds, true)) {
            $this->productIds[] = $productId;
        }
    }

    public function remove(string $productId): void
    {
        $this->productIds = array_values(array_filter(
            $this->productIds,
            static fn (string $id): bool => $id !== $productId,
        ));
    }

    public function has(string $productId): bool
    {
        return in_array($productId, $this->productIds, true);
    }

    public function userId(): string
    {
        return $this->userId;
    }

    /** @return list<string> */
    public function productIds(): array
    {
        return $this->productIds;
    }
}
