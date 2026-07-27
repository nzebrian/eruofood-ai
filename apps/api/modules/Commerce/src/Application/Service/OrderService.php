<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Application\Service;

use DateTimeImmutable;
use EruoFood\Commerce\Application\DTO\Invoice;
use EruoFood\Commerce\Application\Port\InvoiceGenerator;
use EruoFood\Commerce\Domain\Enum\OrderStatus;
use EruoFood\Commerce\Domain\Exception\CommerceNotFound;
use EruoFood\Commerce\Domain\Exception\NotResourceOwner;
use EruoFood\Commerce\Domain\Order\Order;
use EruoFood\Commerce\Domain\Order\OrderLine;
use EruoFood\Commerce\Domain\Order\OrderRepository;
use EruoFood\Commerce\Domain\Store\StoreRepository;
use EruoFood\Shared\Domain\Paginated;

/**
 * Order reads and lifecycle transitions. Customers see & cancel their own
 * orders; sellers (owners of a store with a line in the order) advance status
 * and see their orders; admins can do both and monitor all orders.
 */
final readonly class OrderService
{
    public function __construct(
        private OrderRepository $orders,
        private StoreRepository $stores,
        private InvoiceGenerator $invoices,
    ) {
    }

    public function getById(string $orderId): Order
    {
        return $this->orders->findById($orderId) ?? throw CommerceNotFound::of('order', $orderId);
    }

    public function forCustomer(string $orderId, string $userId, bool $actorIsAdmin): Order
    {
        $order = $this->getById($orderId);
        if (! $actorIsAdmin && ! $order->isForCustomer($userId) && ! $this->actorSellsInOrder($order, $userId)) {
            throw new NotResourceOwner();
        }

        return $order;
    }

    /** @return Paginated<Order> */
    public function history(string $userId, int $page, int $perPage): Paginated
    {
        return $this->orders->forCustomer($userId, $page, $perPage);
    }

    /** @return Paginated<Order> */
    public function forStore(string $storeId, string $actorUserId, bool $actorIsAdmin, ?OrderStatus $status, int $page, int $perPage): Paginated
    {
        $store = $this->stores->findById($storeId) ?? throw CommerceNotFound::of('store', $storeId);
        if (! $actorIsAdmin && ! $store->isOwnedBy($actorUserId)) {
            throw new NotResourceOwner();
        }

        return $this->orders->forStore($storeId, $status, $page, $perPage);
    }

    /** @return Paginated<Order> */
    public function all(?OrderStatus $status, int $page, int $perPage): Paginated
    {
        return $this->orders->all($status, $page, $perPage);
    }

    public function cancel(string $orderId, string $userId, bool $actorIsAdmin): Order
    {
        $order = $this->getById($orderId);
        if (! $actorIsAdmin && ! $order->isForCustomer($userId)) {
            throw new NotResourceOwner();
        }
        $order->cancel(new DateTimeImmutable());
        $this->orders->save($order);

        return $order;
    }

    public function advance(string $orderId, OrderStatus $next, string $actorUserId, bool $actorIsAdmin): Order
    {
        $order = $this->getById($orderId);
        if (! $actorIsAdmin && ! $this->actorSellsInOrder($order, $actorUserId)) {
            throw new NotResourceOwner();
        }
        $order->transitionTo($next, new DateTimeImmutable());
        $this->orders->save($order);

        return $order;
    }

    public function invoice(string $orderId, string $userId, bool $actorIsAdmin): Invoice
    {
        return $this->invoices->forOrder($this->forCustomer($orderId, $userId, $actorIsAdmin));
    }

    private function actorSellsInOrder(Order $order, string $actorUserId): bool
    {
        $storeIds = array_values(array_unique(array_map(
            static fn (OrderLine $l): string => $l->storeId,
            $order->lines(),
        )));
        foreach ($storeIds as $storeId) {
            $store = $this->stores->findById($storeId);
            if ($store !== null && $store->isOwnedBy($actorUserId)) {
                return true;
            }
        }

        return false;
    }
}
