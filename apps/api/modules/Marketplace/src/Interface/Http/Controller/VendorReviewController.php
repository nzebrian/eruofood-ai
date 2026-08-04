<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Interface\Http\Controller;

use EruoFood\Marketplace\Application\Service\MarketplacePresenter;
use EruoFood\Marketplace\Application\Service\VendorReviewService;
use EruoFood\Marketplace\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Marketplace\Interface\Http\Concerns\RespondsWithData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Submit a vendor rating & review (authenticated). */
final readonly class VendorReviewController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private VendorReviewService $reviews,
        private MarketplacePresenter $presenter,
    ) {
    }

    public function store(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $review = $this->reviews->submit(
            $id,
            $this->currentUserId($request),
            (int) $validated['rating'],
            isset($validated['comment']) ? (string) $validated['comment'] : null,
        );

        return $this->data($this->presenter->review($review), 201);
    }
}
