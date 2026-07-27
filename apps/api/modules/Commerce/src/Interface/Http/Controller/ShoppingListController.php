<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Interface\Http\Controller;

use EruoFood\Commerce\Application\Service\CommercePresenter;
use EruoFood\Commerce\Application\Service\ShoppingListService;
use EruoFood\Commerce\Domain\Shopping\ShoppingList;
use EruoFood\Commerce\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Commerce\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Smart shopping lists, including AI-assisted list building. */
final readonly class ShoppingListController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private ShoppingListService $lists,
        private CommercePresenter $presenter,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $lists = $this->lists->forUser($this->currentUserId($request));

        return $this->data(array_map(fn (ShoppingList $l): array => $this->presenter->shoppingList($l), $lists));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);
        $list = $this->lists->create($this->currentUserId($request), (string) $data['name']);

        return $this->data($this->presenter->shoppingList($list), 201);
    }

    public function build(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'prompt' => ['required', 'string', 'max:500'],
        ]);
        $list = $this->lists->buildFromPrompt(
            $this->currentUserId($request),
            (string) $data['name'],
            (string) $data['prompt'],
        );

        return $this->data($this->presenter->shoppingList($list), 201);
    }

    public function addLine(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'product_id' => ['nullable', 'uuid'],
        ]);
        $list = $this->lists->addLine(
            $id,
            $this->currentUserId($request),
            (string) $data['name'],
            (int) ($data['quantity'] ?? 1),
            isset($data['product_id']) ? (string) $data['product_id'] : null,
        );

        return $this->data($this->presenter->shoppingList($list));
    }

    public function toggleLine(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'index' => ['required', 'integer', 'min:0'],
            'bought' => ['required', 'boolean'],
        ]);
        $list = $this->lists->toggleLine(
            $id,
            $this->currentUserId($request),
            (int) $data['index'],
            (bool) $data['bought'],
        );

        return $this->data($this->presenter->shoppingList($list));
    }

    public function removeLine(Request $request, string $id, int $index): JsonResponse
    {
        $list = $this->lists->removeLine($id, $this->currentUserId($request), $index);

        return $this->data($this->presenter->shoppingList($list));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->lists->delete($id, $this->currentUserId($request));

        return new JsonResponse(null, 204);
    }
}
