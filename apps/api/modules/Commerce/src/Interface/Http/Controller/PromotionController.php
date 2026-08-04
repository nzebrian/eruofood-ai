<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Interface\Http\Controller;

use EruoFood\Commerce\Application\Service\CommercePresenter;
use EruoFood\Commerce\Application\Service\PromotionService;
use EruoFood\Commerce\Domain\Promotion\Promotion;
use EruoFood\Commerce\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;

/** Public promotions & flash sales. */
final readonly class PromotionController
{
    use RespondsWithData;

    public function __construct(
        private PromotionService $promotions,
        private CommercePresenter $presenter,
    ) {
    }

    public function index(): JsonResponse
    {
        return $this->data(array_map(
            fn (Promotion $p): array => $this->presenter->promotion($p),
            $this->promotions->active(),
        ));
    }

    public function flashSales(): JsonResponse
    {
        return $this->data(array_map(
            fn (Promotion $p): array => $this->presenter->promotion($p),
            $this->promotions->flashSales(),
        ));
    }
}
