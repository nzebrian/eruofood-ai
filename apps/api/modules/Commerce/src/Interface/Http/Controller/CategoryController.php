<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Interface\Http\Controller;

use EruoFood\Commerce\Application\Service\CategoryService;
use EruoFood\Commerce\Application\Service\CommercePresenter;
use EruoFood\Commerce\Domain\Catalog\Category;
use EruoFood\Commerce\Domain\Enum\GroceryDepartment;
use EruoFood\Commerce\Domain\Enum\ProductKind;
use EruoFood\Commerce\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Public category listing (the storefront taxonomy incl. grocery departments). */
final readonly class CategoryController
{
    use RespondsWithData;

    public function __construct(
        private CategoryService $categories,
        private CommercePresenter $presenter,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $kind = $request->filled('kind') ? ProductKind::tryFrom((string) $request->string('kind')) : null;
        $department = $request->filled('department') ? GroceryDepartment::tryFrom((string) $request->string('department')) : null;

        $categories = $this->categories->list($kind, $department);

        return $this->data(array_map(fn (Category $c): array => $this->presenter->category($c), $categories));
    }
}
