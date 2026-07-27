<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Interface\Http\Controller;

use EruoFood\Commerce\Application\Service\CommercePresenter;
use EruoFood\Commerce\Application\Service\ShoppingAssistantService;
use EruoFood\Commerce\Domain\Catalog\Product;
use EruoFood\Commerce\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Commerce\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** AI-powered shopping: recommendations, cross-sell, up-sell and the assistant. */
final readonly class AssistantController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private ShoppingAssistantService $assistant,
        private CommercePresenter $presenter,
    ) {
    }

    public function recommendations(Request $request): JsonResponse
    {
        return $this->suggestion($this->assistant->recommendations($this->currentUserIdOrNull($request)));
    }

    public function crossSell(Request $request, string $productId): JsonResponse
    {
        return $this->suggestion($this->assistant->crossSell($productId, $this->currentUserIdOrNull($request)));
    }

    public function upSell(Request $request, string $productId): JsonResponse
    {
        return $this->suggestion($this->assistant->upSell($productId, $this->currentUserIdOrNull($request)));
    }

    public function assist(Request $request): JsonResponse
    {
        $data = $request->validate(['question' => ['required', 'string', 'max:500']]);
        $answer = $this->assistant->assist((string) $data['question'], $this->currentUserIdOrNull($request));

        return $this->data(['answer' => $answer]);
    }

    /**
     * @param array{products: list<Product>, blurb: string} $suggestion
     */
    private function suggestion(array $suggestion): JsonResponse
    {
        return $this->data([
            'blurb' => $suggestion['blurb'],
            'products' => array_map(
                fn (Product $p): array => $this->presenter->productSummary($p),
                $suggestion['products'],
            ),
        ]);
    }
}
