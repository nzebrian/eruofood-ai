<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Infrastructure\Order;

use EruoFood\Commerce\Application\Input\CheckoutInput;
use EruoFood\Commerce\Application\Service\CheckoutService;
use EruoFood\Commerce\Application\Service\OrderService;
use EruoFood\Commerce\Domain\Order\Order;
use EruoFood\PublicApi\Domain\Order\OrderDraft;
use EruoFood\PublicApi\Domain\Order\OrderPort;
use EruoFood\PublicApi\Domain\Order\OrderResource;
use EruoFood\Shared\Domain\Paginated;

/**
 * Adapts the Public API's {@see OrderPort} onto the Commerce Order domain's
 * application services. It NEVER touches Commerce persistence or assembles an
 * order itself — reads/writes go through `OrderService`/`CheckoutService`, which
 * own all pricing, inventory and lifecycle rules and already enforce ownership
 * (they throw `NotResourceOwner` for a mismatched user). Every call passes the
 * authenticated customer's id and `actorIsAdmin = false`, so a public caller can
 * only ever act on its own subject's orders (defence in depth against BOLA).
 */
final readonly class CommerceOrderAdapter implements OrderPort
{
    public function __construct(
        private OrderService $orders,
        private CheckoutService $checkout,
    ) {
    }

    public function listForCustomer(string $userId, int $page, int $perPage): Paginated
    {
        $result = $this->orders->history($userId, $page, $perPage);

        return new Paginated(
            array_map(fn (Order $o): OrderResource => $this->toResource($o), $result->items),
            $result->total,
            $result->page,
            $result->perPage,
        );
    }

    public function getForCustomer(string $orderId, string $userId): OrderResource
    {
        // OrderService::forCustomer enforces ownership (throws NotResourceOwner).
        return $this->toResource($this->orders->forCustomer($orderId, $userId, false));
    }

    public function create(string $userId, OrderDraft $draft): OrderResource
    {
        $input = CheckoutInput::fromArray([
            'pickup' => $draft->pickup,
            'note' => $draft->note,
            'scheduled_for' => $draft->scheduledFor,
            'shipping_address' => $draft->shippingAddress,
        ]);

        return $this->toResource($this->checkout->place($userId, $input));
    }

    public function cancel(string $orderId, string $userId): OrderResource
    {
        return $this->toResource($this->orders->cancel($orderId, $userId, false));
    }

    private function toResource(Order $o): OrderResource
    {
        return new OrderResource(
            $o->id(),
            $o->reference(),
            $o->status()->value,
            $o->customerUserId(),
            $o->total()->minor,
            $o->total()->currency,
            $o->isPickup(),
            $o->note(),
            array_map(static fn (object $line): array => $line->toArray(), $o->lines()),
            $o->placedAt()->format(DATE_ATOM),
        );
    }
}
