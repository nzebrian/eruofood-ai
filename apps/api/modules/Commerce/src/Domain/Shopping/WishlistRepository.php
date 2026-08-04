<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Shopping;

/** Persistence port for the {@see Wishlist} aggregate. */
interface WishlistRepository
{
    public function forUser(string $userId): ?Wishlist;

    public function save(Wishlist $wishlist): void;
}
