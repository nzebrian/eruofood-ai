<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Interface\Http\Controller;

use EruoFood\Commerce\Application\Input\CheckoutInput;
use EruoFood\Commerce\Application\Service\CheckoutService;
use EruoFood\Commerce\Application\Service\CommercePresenter;
use EruoFood\Commerce\Interface\Http\Concerns\ResolvesAuthUser;
use EruoFood\Commerce\Interface\Http\Concerns\RespondsWithData;
use EruoFood\Commerce\Interface\Http\Request\CheckoutRequest;
use EruoFood\Shared\Domain\Idempotency\IdempotencyStore;
use EruoFood\Shared\Interface\Http\Concerns\UsesIdempotencyKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Checkout: price quote and order placement. */
final readonly class CheckoutController
{
    use RespondsWithData;
    use ResolvesAuthUser;
    use UsesIdempotencyKey;

    public function __construct(
        private CheckoutService $checkout,
        private CommercePresenter $presenter,
        private IdempotencyStore $idempotency,
    ) {
    }

    public function quote(Request $request): JsonResponse
    {
        $breakdown = $this->checkout->quote($this->currentUserId($request), $request->boolean('pickup'));

        return $this->data($breakdown->toArray());
    }

    /**
     * Place the order. Retry-safe when the caller sends an `Idempotency-Key`:
     * a repeat returns the original order instead of deducting stock and
     * redeeming the coupon a second time.
     */
    public function store(CheckoutRequest $request): JsonResponse
    {
        $userId = $this->currentUserId($request);
        $validated = $request->validated();

        $result = $this->idempotency->execute(
            'commerce.checkout',
            $this->idempotencyKey($request),
            $this->requestFingerprint($validated + ['actor' => $userId]),
            function () use ($userId, $validated): array {
                $order = $this->checkout->place($userId, CheckoutInput::fromArray($validated));

                return $this->presenter->order($order);
            },
        );

        return $this->data($result->value, $result->replayed ? 200 : 201);
    }
}
