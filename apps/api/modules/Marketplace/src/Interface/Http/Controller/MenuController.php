<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Interface\Http\Controller;

use EruoFood\Marketplace\Application\Service\MarketplacePresenter;
use EruoFood\Marketplace\Application\Service\MenuService;
use EruoFood\Marketplace\Domain\Menu\MenuCategory;
use EruoFood\Marketplace\Domain\Menu\MenuItem;
use EruoFood\Marketplace\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;

/** Public read access to a vendor's menu. */
final readonly class MenuController
{
    use RespondsWithData;

    public function __construct(
        private MenuService $menu,
        private MarketplacePresenter $presenter,
    ) {
    }

    public function categories(string $vendorId): JsonResponse
    {
        return $this->data(array_map(
            fn (MenuCategory $c): array => $this->presenter->category($c),
            $this->menu->categoriesFor($vendorId),
        ));
    }

    public function items(string $vendorId): JsonResponse
    {
        return $this->data(array_map(
            fn (MenuItem $i): array => $this->presenter->menuItem($i),
            $this->menu->itemsFor($vendorId),
        ));
    }
}
