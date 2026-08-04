<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Interface\Http\Controller\Admin;

use EruoFood\Catalog\Application\Service\CatalogPresenter;
use EruoFood\Catalog\Application\Service\CategoryService;
use EruoFood\Catalog\Domain\Enum\CategoryType;
use EruoFood\Catalog\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admin category management. */
final readonly class CategoryAdminController
{
    use RespondsWithData;

    public function __construct(
        private CategoryService $categories,
        private CatalogPresenter $presenter,
    ) {
    }

    public function index(): JsonResponse
    {
        return $this->data(array_map(
            fn ($c): array => $this->presenter->category($c),
            $this->categories->list(onlyActive: false),
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);
        $category = $this->categories->create(
            $data['name'],
            CategoryType::from($data['type']),
            $data['description'] ?? null,
            (int) ($data['sort_order'] ?? 0),
        );

        return $this->data($this->presenter->category($category), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $this->validateData($request);
        $category = $this->categories->update(
            $id,
            $data['name'],
            CategoryType::from($data['type']),
            $data['description'] ?? null,
            (int) ($data['sort_order'] ?? 0),
        );

        return $this->data($this->presenter->category($category));
    }

    public function setActive(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['active' => ['required', 'boolean']]);

        return $this->data($this->presenter->category($this->categories->setActive($id, (bool) $validated['active'])));
    }

    public function destroy(string $id): JsonResponse
    {
        $this->categories->delete($id);

        return new JsonResponse(null, 204);
    }

    /** @return array<string, mixed> */
    private function validateData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'in:soup,swallow,rice,protein,snack,street_food,drink,dessert,breakfast,side_dish'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
