<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Interface\Http\Controller;

use EruoFood\Commerce\Application\Service\CommercePresenter;
use EruoFood\Commerce\Application\Service\ProductReviewService;
use EruoFood\Commerce\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Commerce\Interface\Http\Concerns\RespondsWithData;
use EruoFood\Commerce\Interface\Http\Request\ReviewRequest;
use Illuminate\Http\JsonResponse;

/** Product ratings & reviews (auth; one per user per product). */
final readonly class ProductReviewController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private ProductReviewService $reviews,
        private CommercePresenter $presenter,
    ) {
    }

    public function store(ReviewRequest $request, string $productId): JsonResponse
    {
        $data = $request->validated();
        $review = $this->reviews->add(
            $productId,
            $this->currentUserId($request),
            (int) $data['rating'],
            isset($data['comment']) ? (string) $data['comment'] : null,
        );

        return $this->data($this->presenter->review($review), 201);
    }
}
