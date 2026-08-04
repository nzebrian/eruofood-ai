<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Interface\Http\Controller;

use EruoFood\Commerce\Application\Input\StoreInput;
use EruoFood\Commerce\Application\Service\CommercePresenter;
use EruoFood\Commerce\Application\Service\ProductService;
use EruoFood\Commerce\Application\Service\StoreService;
use EruoFood\Commerce\Domain\Catalog\Product;
use EruoFood\Commerce\Domain\Store\Store;
use EruoFood\Commerce\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Commerce\Interface\Http\Concerns\RespondsWithData;
use EruoFood\Commerce\Interface\Http\Request\StoreRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Store discovery (public) and onboarding/management (owner). */
final readonly class StoreController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private StoreService $stores,
        private ProductService $products,
        private CommercePresenter $presenter,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->stores->list(
            ((string) $request->string('q')) ?: null,
            (int) $request->integer('page', 1),
            (int) $request->integer('per_page', 20),
        );

        return $this->paginated($page, fn (Store $s): array => $this->presenter->storeSummary($s));
    }

    public function show(string $slug): JsonResponse
    {
        return $this->data($this->presenter->store($this->stores->getBySlug($slug)));
    }

    public function products(Request $request, string $storeId): JsonResponse
    {
        $store = $this->stores->getById($storeId);
        $page = $this->products->search(
            new \EruoFood\Commerce\Domain\Catalog\ProductSearchCriteria(storeId: $store->id(), sort: 'newest'),
            (int) $request->integer('page', 1),
            (int) $request->integer('per_page', 20),
        );

        return $this->paginated($page, fn (Product $p): array => $this->presenter->productSummary($p));
    }

    public function mine(Request $request): JsonResponse
    {
        $stores = $this->stores->mine($this->currentUserId($request));

        return $this->data(array_map(fn (Store $s): array => $this->presenter->store($s), $stores));
    }

    public function store(StoreRequest $request): JsonResponse
    {
        $store = $this->stores->register($this->currentUserId($request), (string) $request->validated()['name']);
        // Apply the rest of the profile if provided.
        $store = $this->stores->update($store->id(), $this->currentUserId($request), false, StoreInput::fromArray($request->validated()));

        return $this->data($this->presenter->store($store), 201);
    }

    public function update(StoreRequest $request, string $id): JsonResponse
    {
        $store = $this->stores->update(
            $id,
            $this->currentUserId($request),
            $this->actorIsAdmin($request),
            StoreInput::fromArray($request->validated()),
        );

        return $this->data($this->presenter->store($store));
    }
}
