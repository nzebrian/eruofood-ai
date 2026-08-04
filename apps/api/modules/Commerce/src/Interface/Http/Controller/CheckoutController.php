<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Interface\Http\Controller;

use EruoFood\Commerce\Application\Input\CheckoutInput;
use EruoFood\Commerce\Application\Service\CheckoutService;
use EruoFood\Commerce\Application\Service\CommercePresenter;
use EruoFood\Commerce\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Commerce\Interface\Http\Concerns\RespondsWithData;
use EruoFood\Commerce\Interface\Http\Request\CheckoutRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Checkout: price quote and order placement. */
final readonly class CheckoutController
{
    use RespondsWithData;
    use ResolvesAuthUser;

    public function __construct(
        private CheckoutService $checkout,
        private CommercePresenter $presenter,
    ) {
    }

    public function quote(Request $request): JsonResponse
    {
        $breakdown = $this->checkout->quote($this->currentUserId($request), $request->boolean('pickup'));

        return $this->data($breakdown->toArray());
    }

    public function store(CheckoutRequest $request): JsonResponse
    {
        $order = $this->checkout->place($this->currentUserId($request), CheckoutInput::fromArray($request->validated()));

        return $this->data($this->presenter->order($order), 201);
    }
}
