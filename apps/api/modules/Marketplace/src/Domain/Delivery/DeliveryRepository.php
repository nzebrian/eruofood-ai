<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\Delivery;

interface DeliveryRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?Delivery;

    public function findByOrder(string $orderId): ?Delivery;

    /**
     * A rider's active (not delivered/failed) deliveries.
     *
     * @return list<Delivery>
     */
    public function activeForRider(string $riderId): array;

    public function save(Delivery $delivery): void;
}
