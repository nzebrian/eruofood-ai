<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Interface\Http\Controller\Admin;

use EruoFood\Commerce\Application\Service\CategoryService;
use EruoFood\Commerce\Application\Service\CommercePresenter;
use EruoFood\Commerce\Domain\Enum\GroceryDepartment;
use EruoFood\Commerce\Domain\Enum\ProductKind;
use EruoFood\Commerce\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admin-curated product categories. */
final readonly class CategoryAdminController
{
    use RespondsWithData;

    public function __construct(
        private CategoryService $categories,
        private CommercePresenter $presenter,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', 'in:grocery,general'],
            'department' => ['nullable', 'in:produce,pantry,beverages,frozen,household,other'],
            'parent_id' => ['nullable', 'uuid'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
        $category = $this->categories->create(
            (string) $data['name'],
            ProductKind::from((string) $data['kind']),
            isset($data['department']) ? GroceryDepartment::from((string) $data['department']) : null,
            isset($data['parent_id']) ? (string) $data['parent_id'] : null,
            (int) ($data['sort_order'] ?? 0),
        );

        return $this->data($this->presenter->category($category), 201);
    }

    public function destroy(string $id): JsonResponse
    {
        $this->categories->delete($id);

        return new JsonResponse(null, 204);
    }
}
