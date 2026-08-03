<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Read;

use EruoFood\Shared\Domain\Paginated;

/** Port for reading restaurants + menus, implemented over the Marketplace context. */
interface RestaurantReadPort
{
    /** @return Paginated<RestaurantResource> */
    public function restaurants(ResourceQuery $query): Paginated;

    public function restaurant(string $slug): ?RestaurantResource;

    /** @return list<MenuItemResource> */
    public function menu(string $restaurantId): array;
}
