<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Application\Service;

use EruoFood\Marketplace\Domain\Menu\MenuItem;
use EruoFood\Marketplace\Domain\Menu\MenuItemRepository;
use EruoFood\Marketplace\Domain\Vendor\Vendor;
use EruoFood\Marketplace\Domain\Vendor\VendorSearchCriteria;
use EruoFood\Shared\Domain\Paginated;

/**
 * Search & discovery across the marketplace: find verified vendors (by name,
 * type, category, or geolocation) and available menu items.
 */
final readonly class SearchService
{
    public function __construct(
        private VendorService $vendors,
        private MenuItemRepository $items,
    ) {
    }

    /**
     * @return Paginated<Vendor>
     */
    public function vendors(VendorSearchCriteria $criteria, int $page, int $perPage): Paginated
    {
        return $this->vendors->search($criteria, $page, $perPage);
    }

    /**
     * @return Paginated<MenuItem>
     */
    public function items(?string $term, ?string $vendorId, bool $featuredOnly, int $page, int $perPage): Paginated
    {
        return $this->items->search($term, $vendorId, $featuredOnly, max(1, $page), min(60, max(1, $perPage)));
    }
}
