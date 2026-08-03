<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Infrastructure\Read;

use EruoFood\Marketplace\Domain\Menu\MenuItem;
use EruoFood\Marketplace\Domain\Menu\MenuItemRepository;
use EruoFood\Marketplace\Domain\Vendor\Vendor;
use EruoFood\Marketplace\Domain\Vendor\VendorRepository;
use EruoFood\Marketplace\Domain\Vendor\VendorSearchCriteria;
use EruoFood\PublicApi\Domain\Read\MenuItemResource;
use EruoFood\PublicApi\Domain\Read\ResourceQuery;
use EruoFood\PublicApi\Domain\Read\RestaurantReadPort;
use EruoFood\PublicApi\Domain\Read\RestaurantResource;
use EruoFood\Shared\Domain\Paginated;

/**
 * Adapts the Public API's {@see RestaurantReadPort} onto the Marketplace context.
 * A sanctioned cross-context read seam: it consumes Marketplace's published
 * vendor/menu repositories (never their tables or write model) and maps to the
 * Public API's own resource DTOs, so the external contract stays independent of
 * Marketplace's internal Vendor/MenuItem shapes. Only verified vendors and their
 * available items are ever exposed (the repositories enforce this).
 */
final readonly class MarketplaceReadAdapter implements RestaurantReadPort
{
    public function __construct(
        private VendorRepository $vendors,
        private MenuItemRepository $items,
    ) {
    }

    public function restaurants(ResourceQuery $query): Paginated
    {
        $criteria = new VendorSearchCriteria(
            term: $query->search,
            featuredOnly: ($query->filters['featured'] ?? null) === 'true',
        );
        $page = $this->vendors->search($criteria, $query->page, $query->perPage);

        return new Paginated(
            array_map(fn (Vendor $v): RestaurantResource => $this->toRestaurant($v), $page->items),
            $page->total,
            $page->page,
            $page->perPage,
        );
    }

    public function restaurant(string $slug): ?RestaurantResource
    {
        $vendor = $this->vendors->findBySlug($slug);

        return $vendor !== null && $vendor->canTrade() ? $this->toRestaurant($vendor) : null;
    }

    public function menu(string $restaurantId): array
    {
        return array_values(array_map(
            fn (MenuItem $i): MenuItemResource => $this->toMenuItem($i),
            $this->items->forVendor($restaurantId, onlyAvailable: true),
        ));
    }

    private function toRestaurant(Vendor $v): RestaurantResource
    {
        return new RestaurantResource(
            $v->id(),
            (string) $v->slug(),
            $v->name(),
            $v->type()->value,
            $v->category(),
            $v->description(),
            $v->isFeatured(),
            $v->images(),
        );
    }

    private function toMenuItem(MenuItem $i): MenuItemResource
    {
        $price = $i->basePrice();

        return new MenuItemResource(
            $i->id(),
            $i->vendorId(),
            $i->categoryId(),
            $i->name(),
            $i->description(),
            $price->minorUnits,
            $price->currency,
            $i->isAvailable(),
            $i->tags(),
        );
    }
}
