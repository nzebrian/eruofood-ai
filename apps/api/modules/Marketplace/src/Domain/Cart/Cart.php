<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\Cart;

use EruoFood\Marketplace\Domain\Exception\MarketplaceInvalidState;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * A user's shopping cart. Bound to a single vendor at a time (food-delivery
 * carts don't mix vendors); adding an item from a different vendor requires
 * clearing the cart first. Same item+variant lines merge by quantity.
 */
final class Cart
{
    /**
     * @param array<string, CartItem> $items keyed by CartItem::key()
     */
    private function __construct(
        private readonly string $userId,
        private ?string $vendorId,
        private array $items,
        private readonly string $currency,
    ) {
    }

    public static function forUser(string $userId, string $currency): self
    {
        return new self($userId, null, [], $currency);
    }

    /**
     * @param list<CartItem> $items
     */
    public static function reconstitute(string $userId, ?string $vendorId, array $items, string $currency): self
    {
        $keyed = [];
        foreach ($items as $item) {
            $keyed[$item->key()] = $item;
        }

        return new self($userId, $vendorId, $keyed, $currency);
    }

    public function add(string $vendorId, CartItem $item): void
    {
        if ($this->vendorId !== null && $this->vendorId !== $vendorId) {
            throw new MarketplaceInvalidState(
                'Your cart contains items from another vendor. Clear it before adding these.',
            );
        }
        $this->vendorId = $vendorId;

        $existing = $this->items[$item->key()] ?? null;
        $this->items[$item->key()] = $existing !== null
            ? $existing->withQuantity($existing->quantity + $item->quantity)
            : $item;
    }

    public function setQuantity(string $menuItemId, ?string $variantName, int $quantity): void
    {
        $key = $menuItemId.'|'.($variantName ?? '');
        $item = $this->items[$key] ?? throw new MarketplaceInvalidState('Item not in cart.');

        if ($quantity <= 0) {
            unset($this->items[$key]);
        } else {
            $this->items[$key] = $item->withQuantity($quantity);
        }
        $this->resetVendorIfEmpty();
    }

    public function remove(string $menuItemId, ?string $variantName): void
    {
        unset($this->items[$menuItemId.'|'.($variantName ?? '')]);
        $this->resetVendorIfEmpty();
    }

    public function clear(): void
    {
        $this->items = [];
        $this->vendorId = null;
    }

    public function subtotal(): Money
    {
        $total = new Money(0, $this->currency);
        foreach ($this->items as $item) {
            $total = $total->add($item->lineTotal());
        }

        return $total;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    private function resetVendorIfEmpty(): void
    {
        if ($this->items === []) {
            $this->vendorId = null;
        }
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function vendorId(): ?string
    {
        return $this->vendorId;
    }

    /** @return list<CartItem> */
    public function items(): array
    {
        return array_values($this->items);
    }

    public function currency(): string
    {
        return $this->currency;
    }
}
