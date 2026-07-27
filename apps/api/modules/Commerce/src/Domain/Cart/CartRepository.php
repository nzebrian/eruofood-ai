<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Cart;

/** Persistence port for the {@see Cart} aggregate. */
interface CartRepository
{
    public function forUser(string $userId): ?Cart;

    public function save(Cart $cart): void;

    public function clear(string $userId): void;
}
