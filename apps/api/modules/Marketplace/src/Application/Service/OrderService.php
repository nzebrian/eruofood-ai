<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Application\Service;

use EruoFood\Marketplace\Domain\Enum\OrderStatus;
use EruoFood\Marketplace\Domain\Exception\MarketplaceNotFound;
use EruoFood\Marketplace\Domain\Exception\NotVendorOwner;
use EruoFood\Marketplace\Domain\Order\Order;
use EruoFood\Marketplace\Domain\Order\OrderRepository;
use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\Paginated;

/**
 * Order tracking, history and vendor-side order management. Customers see their
 * own orders; vendors manage orders for vendors they own (or admins, any).
 */
final readonly class OrderService
{
    public function __construct(
        private OrderRepository $orders,
        private VendorService $vendors,
        private Clock $clock,
    ) {
    }

    /** View an order (customer, owning vendor, or admin). */
    public function get(string $userId, bool $isAdmin, string $id): Order
    {
        $order = $this->orders->findById($id) ?? throw MarketplaceNotFound::of('order', $id);

        if ($order->isForCustomer($userId) || $isAdmin) {
            return $order;
        }
        if ($this->vendors->get($order->vendorId())->isOwnedBy($userId)) {
            return $order;
        }

        throw new NotVendorOwner('You cannot view this order.');
    }

    /**
     * @return Paginated<Order>
     */
    public function history(string $userId, int $page, int $perPage): Paginated
    {
        return $this->orders->forCustomer($userId, max(1, $page), min(50, max(1, $perPage)));
    }

    /**
     * @return Paginated<Order>
     */
    public function vendorOrders(string $userId, bool $isAdmin, string $vendorId, ?OrderStatus $status, int $page, int $perPage): Paginated
    {
        $this->vendors->manageable($userId, $isAdmin, $vendorId);

        return $this->orders->forVendor($vendorId, $status, max(1, $page), min(50, max(1, $perPage)));
    }

    /** Advance an order's status (owning vendor or admin only). */
    public function advanceStatus(string $userId, bool $isAdmin, string $id, OrderStatus $next): Order
    {
        $order = $this->orders->findById($id) ?? throw MarketplaceNotFound::of('order', $id);
        $this->vendors->manageable($userId, $isAdmin, $order->vendorId());

        $order->transitionTo($next, $this->clock->now());
        $this->orders->save($order);

        return $order;
    }

    /** Cancel an order (the customer who placed it, the owning vendor, or admin). */
    public function cancel(string $userId, bool $isAdmin, string $id): Order
    {
        $order = $this->orders->findById($id) ?? throw MarketplaceNotFound::of('order', $id);

        $vendorOwned = $this->vendors->get($order->vendorId())->isOwnedBy($userId);
        if (! $order->isForCustomer($userId) && ! $vendorOwned && ! $isAdmin) {
            throw new NotVendorOwner('You cannot cancel this order.');
        }

        $order->cancel($this->clock->now());
        $this->orders->save($order);

        return $order;
    }
}
