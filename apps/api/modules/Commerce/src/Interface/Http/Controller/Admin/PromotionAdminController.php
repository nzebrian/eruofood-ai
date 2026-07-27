<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Interface\Http\Controller\Admin;

use DateTimeImmutable;
use EruoFood\Commerce\Application\Service\CommercePresenter;
use EruoFood\Commerce\Application\Service\PromotionService;
use EruoFood\Commerce\Domain\Enum\PromotionType;
use EruoFood\Commerce\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admin promotions & flash sales. */
final readonly class PromotionAdminController
{
    use RespondsWithData;

    public function __construct(
        private PromotionService $promotions,
        private CommercePresenter $presenter,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'store_id' => ['nullable', 'uuid'],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'in:percentage,fixed'],
            'value' => ['required', 'integer', 'min:1'],
            'product_ids' => ['array'],
            'product_ids.*' => ['uuid'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'flash_sale' => ['boolean'],
        ]);
        $promotion = $this->promotions->create(
            isset($data['store_id']) ? (string) $data['store_id'] : null,
            (string) $data['name'],
            PromotionType::from((string) $data['type']),
            (int) $data['value'],
            array_map('strval', $data['product_ids'] ?? []),
            isset($data['starts_at']) ? new DateTimeImmutable((string) $data['starts_at']) : null,
            isset($data['ends_at']) ? new DateTimeImmutable((string) $data['ends_at']) : null,
            (bool) ($data['flash_sale'] ?? false),
        );

        return $this->data($this->presenter->promotion($promotion), 201);
    }

    public function destroy(string $id): JsonResponse
    {
        $this->promotions->delete($id);

        return new JsonResponse(null, 204);
    }
}
