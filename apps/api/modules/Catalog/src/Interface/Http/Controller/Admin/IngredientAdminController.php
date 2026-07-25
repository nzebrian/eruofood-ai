<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Interface\Http\Controller\Admin;

use EruoFood\Catalog\Application\Service\CatalogPresenter;
use EruoFood\Catalog\Application\Service\IngredientService;
use EruoFood\Catalog\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admin ingredient management. */
final readonly class IngredientAdminController
{
    use RespondsWithData;

    public function __construct(
        private IngredientService $ingredients,
        private CatalogPresenter $presenter,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);
        $ingredient = $this->ingredients->create(
            $data['name'],
            $data['description'] ?? null,
            $data['local_names'] ?? [],
            $data['nutrition'] ?? null,
        );

        return $this->data($this->presenter->ingredient($ingredient), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $this->validateData($request);
        $ingredient = $this->ingredients->update(
            $id,
            $data['name'],
            $data['description'] ?? null,
            $data['local_names'] ?? [],
            $data['nutrition'] ?? null,
        );

        return $this->data($this->presenter->ingredient($ingredient));
    }

    public function destroy(string $id): JsonResponse
    {
        $this->ingredients->delete($id);

        return new JsonResponse(null, 204);
    }

    /** @return array<string, mixed> */
    private function validateData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'local_names' => ['nullable', 'array'],
            'local_names.*.name' => ['required', 'string', 'max:120'],
            'local_names.*.language' => ['required', 'string', 'max:60'],
            'nutrition' => ['nullable', 'array'],
        ]);
    }
}
