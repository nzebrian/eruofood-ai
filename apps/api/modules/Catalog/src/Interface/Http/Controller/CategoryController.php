<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Interface\Http\Controller;

use EruoFood\Catalog\Application\Service\CatalogPresenter;
use EruoFood\Catalog\Application\Service\CategoryService;
use EruoFood\Catalog\Domain\Category\Category;
use EruoFood\Catalog\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;

/** Public category listing. */
final readonly class CategoryController
{
    use RespondsWithData;

    public function __construct(
        private CategoryService $categories,
        private CatalogPresenter $presenter,
    ) {
    }

    public function index(): JsonResponse
    {
        $categories = array_map(
            fn (Category $c): array => $this->presenter->category($c),
            $this->categories->list(onlyActive: true),
        );

        return $this->data($categories);
    }
}
