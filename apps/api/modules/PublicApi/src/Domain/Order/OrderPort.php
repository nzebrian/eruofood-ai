<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Domain\Order;

use EruoFood\Shared\Domain\Paginated;

/**
 * The port through which the Public API reads and writes orders. It is
 * implemented by an adapter over the Order domain's application service, so the
 * Public API never bypasses that domain's rules — and every method takes the
 * authenticated customer's user id, which the Order domain uses to enforce
 * ownership (defence in depth against BOLA).
 */
interface OrderPort
{
    /**
     * @return Paginated<OrderResource>
     */
    public function listForCustomer(string $userId, int $page, int $perPage): Paginated;

    /** Throws if the order is not owned by $userId. */
    public function getForCustomer(string $orderId, string $userId): OrderResource;

    /** Create an order for the customer from their cart. */
    public function create(string $userId, OrderDraft $draft): OrderResource;

    /** Cancel the customer's order where business rules permit. */
    public function cancel(string $orderId, string $userId): OrderResource;
}
