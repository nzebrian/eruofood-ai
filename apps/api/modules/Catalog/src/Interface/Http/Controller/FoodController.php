<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Interface\Http\Controller;

use EruoFood\Catalog\Application\Service\CatalogPresenter;
use EruoFood\Catalog\Application\Service\FoodService;
use EruoFood\Catalog\Domain\Enum\FoodRegion;
use EruoFood\Catalog\Domain\Food\Food;
use EruoFood\Catalog\Domain\Food\FoodSearchCriteria;
use EruoFood\Catalog\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Public food catalogue: search/browse and food detail. */
final readonly class FoodController
{
    use RespondsWithData;

    public function __construct(
        private FoodService $foods,
        private CatalogPresenter $presenter,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $criteria = new FoodSearchCriteria(
            term: ((string) $request->string('q')) ?: null,
            categoryId: ((string) $request->string('category_id')) ?: null,
            region: $request->filled('region') ? FoodRegion::tryFrom((string) $request->string('region')) : null,
            state: ((string) $request->string('state')) ?: null,
            tag: ((string) $request->string('tag')) ?: null,
            sort: (string) (((string) $request->string('sort')) ?: 'name'),
        );

        $page = $this->foods->search($criteria, (int) $request->integer('page', 1), (int) $request->integer('per_page', 20));

        return $this->paginated($page, fn (Food $f): array => $this->presenter->foodSummary($f));
    }

    public function show(string $slug): JsonResponse
    {
        return $this->data($this->presenter->food($this->foods->getBySlug($slug)));
    }
}
