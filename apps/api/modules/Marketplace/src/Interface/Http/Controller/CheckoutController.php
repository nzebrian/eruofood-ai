<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Interface\Http\Controller;

use EruoFood\Marketplace\Application\Input\CheckoutInput;
use EruoFood\Marketplace\Application\Service\CheckoutService;
use EruoFood\Marketplace\Application\Service\MarketplacePresenter;
use EruoFood\Marketplace\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Marketplace\Interface\Http\Concerns\RespondsWithData;
use EruoFood\Marketplace\Interface\Http\Request\CheckoutRequest;
use EruoFood\Shared\Domain\Idempotency\IdempotencyStore;
use EruoFood\Shared\Interface\Http\Concerns\UsesIdempotencyKey;
use Illuminate\Http\JsonResponse;

/** Checkout the cart into a placed order. */
final readonly class CheckoutController
{
    use RespondsWithData;
    use ResolvesAuthUser;
    use UsesIdempotencyKey;

    public function __construct(
        private CheckoutService $checkout,
        private MarketplacePresenter $presenter,
        private IdempotencyStore $idempotency,
    ) {
    }

    /**
     * Place the order.
     *
     * Checkout commits stock and creates an order, so a client retrying after a
     * timeout would otherwise order twice. With an `Idempotency-Key` the retry
     * returns the original order (200) rather than placing a new one.
     */
    public function store(CheckoutRequest $request): JsonResponse
    {
        $userId = $this->currentUserId($request);
        $validated = $request->validated();

        $result = $this->idempotency->execute(
            'marketplace.checkout',
            $this->idempotencyKey($request),
            $this->requestFingerprint($validated + ['actor' => $userId]),
            function () use ($userId, $validated): array {
                $order = $this->checkout->checkout($userId, CheckoutInput::fromArray($validated));

                return $this->presenter->order($order);
            },
        );

        return $this->data($result->value, $result->replayed ? 200 : 201);
    }
}
