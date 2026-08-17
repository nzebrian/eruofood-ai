<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Application\Service;

use EruoFood\Marketplace\Domain\Enum\OrderStatus;
use EruoFood\Marketplace\Domain\Exception\MarketplaceNotFound;
use EruoFood\Marketplace\Domain\Exception\NotVendorOwner;
use EruoFood\Marketplace\Domain\Order\Order;
use EruoFood\Marketplace\Domain\Order\OrderRepository;
use EruoFood\Payments\Contracts\MerchantEarningsRecorder;
use EruoFood\Payments\Contracts\SettledOrder;
use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\Paginated;
use Psr\Log\LoggerInterface;
use Throwable;

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
        // The published Payments contract, never a Payments internal — the same
        // direction of dependency `PaymentInitiator` already establishes.
        private MerchantEarningsRecorder $earnings,
        private LoggerInterface $log,
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

        if ($next === OrderStatus::Delivered) {
            $this->recordMerchantEarnings($order);
        }

        return $order;
    }

    /**
     * Tell Payments that this order is financially final.
     *
     * Delivered is the point at which the vendor has done what they were paid
     * for, so it is the point at which the platform starts owing them. Earlier
     * would accrue against orders that may still be cancelled; later would need
     * a second concept of "done" that nothing else uses.
     *
     * ## Identity only
     *
     * {@see SettledOrder} carries three ids and no amounts. Marketplace knows
     * *that* the order is complete and *who* completed it; what the platform
     * captured, what commission applied and what is therefore owed are Payments'
     * to derive from its own ledger. A contract that let Marketplace state an
     * amount would be a contract that let it be wrong.
     *
     * ## Never fails the transition
     *
     * The order *is* delivered. If accrual is switched off, misconfigured, or
     * broken, that fact does not become untrue, and a vendor should not be
     * unable to mark a delivery complete because settlement has a problem. The
     * accrual is recoverable — it is derived from the ledger, so a later
     * backfill produces the same row — and a lost delivery transition is not.
     */
    private function recordMerchantEarnings(Order $order): void
    {
        try {
            $this->earnings->recordSettledOrder(
                new SettledOrder($order->id(), 'vendor', $order->vendorId()),
            );
        } catch (Throwable $e) {
            $this->log->error('marketplace.order.accrual_failed', [
                'order_id' => $order->id(),
                'vendor_id' => $order->vendorId(),
                // The class rather than the message: this path is one step from
                // payment data, and an exception message can carry it.
                'exception' => $e::class,
            ]);
        }
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
