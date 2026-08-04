<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Cart;

use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * A user's shopping cart. Unlike the food-delivery cart, a commerce cart is
 * multi-store: a single order can contain products from several sellers. Same
 * product+variant lines merge by quantity. An optional coupon code is captured
 * on the cart and validated at checkout.
 */
final class Cart
{
    /**
     * @param array<string, CartItem> $items keyed by CartItem::key()
     */
    private function __construct(
        private readonly string $userId,
        private array $items,
        private ?string $couponCode,
        private readonly string $currency,
    ) {
    }

    public static function forUser(string $userId, string $currency): self
    {
        return new self($userId, [], null, $currency);
    }

    /**
     * @param list<CartItem> $items
     */
    public static function reconstitute(string $userId, array $items, ?string $couponCode, string $currency): self
    {
        $keyed = [];
        foreach ($items as $item) {
            $keyed[$item->key()] = $item;
        }

        return new self($userId, $keyed, $couponCode, $currency);
    }

    public function add(CartItem $item): void
    {
        $existing = $this->items[$item->key()] ?? null;
        $this->items[$item->key()] = $existing !== null
            ? $existing->withQuantity($existing->quantity + $item->quantity)
            : $item;
    }

    public function setQuantity(string $productId, ?string $variantSku, int $quantity): void
    {
        $key = $productId.'|'.($variantSku ?? '');
        $item = $this->items[$key] ?? null;
        if ($item === null) {
            return;
        }
        if ($quantity <= 0) {
            unset($this->items[$key]);
        } else {
            $this->items[$key] = $item->withQuantity($quantity);
        }
    }

    public function remove(string $productId, ?string $variantSku): void
    {
        unset($this->items[$productId.'|'.($variantSku ?? '')]);
    }

    public function applyCoupon(?string $code): void
    {
        $this->couponCode = $code !== null && trim($code) !== '' ? strtoupper(trim($code)) : null;
    }

    public function clear(): void
    {
        $this->items = [];
        $this->couponCode = null;
    }

    public function subtotal(): Money
    {
        $total = new Money(0, $this->currency);
        foreach ($this->items as $item) {
            $total = $total->add($item->lineTotal());
        }

        return $total;
    }

    public function itemCount(): int
    {
        $count = 0;
        foreach ($this->items as $item) {
            $count += $item->quantity;
        }

        return $count;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function userId(): string
    {
        return $this->userId;
    }

    /** @return list<CartItem> */
    public function items(): array
    {
        return array_values($this->items);
    }

    public function couponCode(): ?string
    {
        return $this->couponCode;
    }

    public function currency(): string
    {
        return $this->currency;
    }
}
