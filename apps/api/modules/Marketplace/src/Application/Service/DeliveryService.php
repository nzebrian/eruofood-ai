<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Application\Service;

use EruoFood\Marketplace\Domain\Delivery\Delivery;
use EruoFood\Marketplace\Domain\Delivery\DeliveryRepository;
use EruoFood\Marketplace\Domain\Enum\DeliveryStatus;
use EruoFood\Marketplace\Domain\Enum\RiderStatus;
use EruoFood\Marketplace\Domain\Exception\MarketplaceNotFound;
use EruoFood\Marketplace\Domain\Exception\NotVendorOwner;
use EruoFood\Marketplace\Domain\Rider\RiderRepository;
use EruoFood\Marketplace\Domain\ValueObject\GeoLocation;
use EruoFood\Shared\Domain\Clock;

/**
 * Delivery assignment and live tracking. Vendors (or admins) assign a rider to
 * an order's delivery; the assigned rider progresses its status and streams
 * location breadcrumbs.
 */
final readonly class DeliveryService
{
    public function __construct(
        private DeliveryRepository $deliveries,
        private RiderRepository $riders,
        private VendorService $vendors,
        private OrderService $orders,
        private Clock $clock,
    ) {
    }

    /** View the delivery for an order (customer, owning vendor, or admin). */
    public function forOrder(string $userId, bool $isAdmin, string $orderId): Delivery
    {
        $this->orders->get($userId, $isAdmin, $orderId); // authorises

        return $this->deliveries->findByOrder($orderId) ?? throw MarketplaceNotFound::of('delivery', $orderId);
    }

    /** Assign a rider (owning vendor or admin). */
    public function assignRider(string $userId, bool $isAdmin, string $deliveryId, string $riderId): Delivery
    {
        $delivery = $this->get($deliveryId);
        $this->vendors->manageable($userId, $isAdmin, $delivery->vendorId());

        $rider = $this->riders->findById($riderId) ?? throw MarketplaceNotFound::of('rider', $riderId);
        $now = $this->clock->now();

        $delivery->assignRider($rider->id(), $now);
        $rider->setStatus(RiderStatus::Busy);
        $this->deliveries->save($delivery);
        $this->riders->save($rider);

        return $delivery;
    }

    /** Progress the delivery status (assigned rider or admin). */
    public function advance(string $riderUserId, bool $isAdmin, string $deliveryId, DeliveryStatus $next): Delivery
    {
        $delivery = $this->get($deliveryId);
        $this->assertAssignedRider($riderUserId, $isAdmin, $delivery->riderId());

        $delivery->advanceTo($next, $this->clock->now());
        $this->deliveries->save($delivery);

        // Free the rider once the job ends.
        if (in_array($next, [DeliveryStatus::Delivered, DeliveryStatus::Failed], true) && $delivery->riderId() !== null) {
            $rider = $this->riders->findById($delivery->riderId());
            if ($rider !== null) {
                $rider->setStatus(RiderStatus::Available);
                $this->riders->save($rider);
            }
        }

        return $delivery;
    }

    /** Append a live-tracking point (assigned rider). */
    public function track(string $riderUserId, bool $isAdmin, string $deliveryId, GeoLocation $point): Delivery
    {
        $delivery = $this->get($deliveryId);
        $this->assertAssignedRider($riderUserId, $isAdmin, $delivery->riderId());

        $now = $this->clock->now();
        $delivery->track($point, $now);
        $this->deliveries->save($delivery);

        $rider = $this->riders->findByUser($riderUserId);
        if ($rider !== null) {
            $rider->updateLocation($point);
            $this->riders->save($rider);
        }

        return $delivery;
    }

    private function get(string $id): Delivery
    {
        return $this->deliveries->findById($id) ?? throw MarketplaceNotFound::of('delivery', $id);
    }

    private function assertAssignedRider(string $riderUserId, bool $isAdmin, ?string $deliveryRiderId): void
    {
        if ($isAdmin) {
            return;
        }
        $rider = $this->riders->findByUser($riderUserId);
        if ($rider === null || $rider->id() !== $deliveryRiderId) {
            throw new NotVendorOwner('You are not assigned to this delivery.');
        }
    }
}
