<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\Cart;

/** Persistence port for shopping carts (one active cart per user). */
interface CartRepository
{
    public function forUser(string $userId): ?Cart;

    public function save(Cart $cart): void;

    public function clear(string $userId): void;
}
