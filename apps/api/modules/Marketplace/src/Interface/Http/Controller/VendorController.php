<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Interface\Http\Controller;

use EruoFood\Marketplace\Application\Service\MarketplacePresenter;
use EruoFood\Marketplace\Application\Service\VendorReviewService;
use EruoFood\Marketplace\Application\Service\VendorService;
use EruoFood\Marketplace\Domain\Enum\VendorType;
use EruoFood\Marketplace\Domain\ValueObject\GeoLocation;
use EruoFood\Marketplace\Domain\Vendor\Vendor;
use EruoFood\Marketplace\Domain\Vendor\VendorReview;
use EruoFood\Marketplace\Domain\Vendor\VendorSearchCriteria;
use EruoFood\Marketplace\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Public vendor discovery: browse/search, storefront detail, and reviews. */
final readonly class VendorController
{
    use RespondsWithData;

    public function __construct(
        private VendorService $vendors,
        private VendorReviewService $reviews,
        private MarketplacePresenter $presenter,
    ) {
    }

    public function index(Request $request): JsonResponse
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
            sort: (string) (((string) $request->string('sort')) ?: 'rating'),
        );

        $page = $this->vendors->search($criteria, (int) $request->integer('page', 1), (int) $request->integer('per_page', 20));

        return $this->paginated($page, fn (Vendor $v): array => $this->presenter->vendorSummary($v));
    }

    public function show(string $slug): JsonResponse
    {
        return $this->data($this->presenter->vendor($this->vendors->getBySlug($slug)));
    }

    public function reviews(Request $request, string $id): JsonResponse
    {
        $page = $this->reviews->list($id, (int) $request->integer('page', 1), (int) $request->integer('per_page', 20));

        return $this->paginated($page, fn (VendorReview $r): array => $this->presenter->review($r));
    }
}
