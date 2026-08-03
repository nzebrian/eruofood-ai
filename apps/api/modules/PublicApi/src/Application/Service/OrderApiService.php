<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Application\Service;

use EruoFood\PublicApi\Domain\Authorization\Principal;
use EruoFood\PublicApi\Domain\Order\OrderDraft;
use EruoFood\PublicApi\Domain\Order\OrderPort;
use EruoFood\PublicApi\Domain\Order\OrderResource;
use EruoFood\Shared\Domain\Paginated;

/**
 * The order operations of the public API. Object-level authorization is enforced
 * here by construction: every call derives the customer id from the
 * authenticated {@see Principal} (`requireSubjectUser`) — a caller can never pass
 * an arbitrary user or order-owner id — and the Order domain re-checks ownership
 * downstream. An application-scoped credential (no subject) is refused outright.
 */
final readonly class OrderApiService
{
    public function __construct(private OrderPort $orders)
    {
    }

    /**
     * @return Paginated<OrderResource>
     */
    public function list(Principal $principal, int $page, int $perPage): Paginated
    {
        return $this->orders->listForCustomer($principal->requireSubjectUser(), $page, $perPage);
    }

    public function get(Principal $principal, string $orderId): OrderResource
    {
        return $this->orders->getForCustomer($orderId, $principal->requireSubjectUser());
    }

    public function create(Principal $principal, OrderDraft $draft): OrderResource
    {
        return $this->orders->create($principal->requireSubjectUser(), $draft);
    }

    public function cancel(Principal $principal, string $orderId): OrderResource
    {
        return $this->orders->cancel($orderId, $principal->requireSubjectUser());
    }
}
