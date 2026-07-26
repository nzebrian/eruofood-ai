<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Interface\Http\Controller;

use EruoFood\Marketplace\Application\Service\MarketplacePresenter;
use EruoFood\Marketplace\Application\Service\SearchService;
use EruoFood\Marketplace\Domain\Enum\VendorType;
use EruoFood\Marketplace\Domain\Menu\MenuItem;
use EruoFood\Marketplace\Domain\ValueObject\GeoLocation;
use EruoFood\Marketplace\Domain\Vendor\Vendor;
use EruoFood\Marketplace\Domain\Vendor\VendorSearchCriteria;
use EruoFood\Marketplace\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Search & discovery across vendors and menu items. */
final readonly class SearchController
{
    use RespondsWithData;

    public function __construct(
        private SearchService $search,
        private MarketplacePresenter $presenter,
    ) {
    }

    public function vendors(Request $request): JsonResponse
    {
        $near = null;
        if ($request->filled('lat') && $request->filled('lng')) {
            $near = new GeoLocation((float) $request->float('lat'), (float) $request->float('lng'));
        }

        $criteria = new VendorSearchCriteria(
            term: ((string) $request->string('q')) ?: null,
            type: $request->filled('type') ? VendorType::tryFrom((string) $request->string('type')) : null,
            category: ((string) $request->string('category')) ?: null,
            near: $near,
            radiusKm: $request->filled('radius_km') ? (float) $request->float('radius_km') : null,
            featuredOnly: $request->boolean('featured'),
            sort: (string) (((string) $request->string('sort')) ?: ($near !== null ? 'nearest' : 'rating')),
        );

        $page = $this->search->vendors($criteria, (int) $request->integer('page', 1), (int) $request->integer('per_page', 20));

        return $this->paginated($page, fn (Vendor $v): array => $this->presenter->vendorSummary($v));
    }

    public function items(Request $request): JsonResponse
    {
        $page = $this->search->items(
            ((string) $request->string('q')) ?: null,
            ((string) $request->string('vendor_id')) ?: null,
            $request->boolean('featured'),
            (int) $request->integer('page', 1),
            (int) $request->integer('per_page', 20),
        );

        return $this->paginated($page, fn (MenuItem $i): array => $this->presenter->menuItem($i));
    }
}
