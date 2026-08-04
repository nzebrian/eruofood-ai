<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Interface\Http\Controller;

use EruoFood\Marketplace\Application\Input\CheckoutInput;
use EruoFood\Marketplace\Application\Service\CheckoutService;
use EruoFood\Marketplace\Application\Service\MarketplacePresenter;
use EruoFood\Marketplace\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Marketplace\Interface\Http\Concerns\RespondsWithData;
use EruoFood\Marketplace\Interface\Http\Request\CheckoutRequest;
use Illuminate\Http\JsonResponse;

/** Checkout the cart into a placed order. */
final readonly class CheckoutController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private CheckoutService $checkout,
        private MarketplacePresenter $presenter,
    ) {
    }

    public function store(CheckoutRequest $request): JsonResponse
    {
        $order = $this->checkout->checkout(
            $this->currentUserId($request),
            CheckoutInput::fromArray($request->validated()),
        );

        return $this->data($this->presenter->order($order), 201);
    }
}
