<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Application\Service;

use EruoFood\Marketplace\Domain\Enum\RiderStatus;
use EruoFood\Marketplace\Domain\Exception\MarketplaceConflict;
use EruoFood\Marketplace\Domain\Exception\MarketplaceNotFound;
use EruoFood\Marketplace\Domain\Rider\Rider;
use EruoFood\Marketplace\Domain\Rider\RiderRepository;
use EruoFood\Marketplace\Domain\ValueObject\GeoLocation;
use EruoFood\Shared\Domain\Clock;

/** Rider onboarding and self-service (availability, live location). */
final readonly class RiderService
{
    public function __construct(
        private RiderRepository $riders,
        private Clock $clock,
    ) {
    }

    public function onboard(string $userId, string $name, string $phone, string $vehicleType): Rider
    {
        if ($this->riders->findByUser($userId) !== null) {
            throw new MarketplaceConflict('You are already registered as a rider.');
        }

        $rider = Rider::onboard($this->riders->nextIdentity(), $userId, $name, $phone, $vehicleType, $this->clock->now());
        $this->riders->save($rider);

        return $rider;
    }

    public function me(string $userId): Rider
    {
        return $this->riders->findByUser($userId) ?? throw MarketplaceNotFound::of('rider', $userId);
    }

    public function setStatus(string $userId, RiderStatus $status): Rider
    {
        $rider = $this->me($userId);
        $rider->setStatus($status);
        $this->riders->save($rider);

        return $rider;
    }

    public function updateLocation(string $userId, GeoLocation $location): Rider
    {
        $rider = $this->me($userId);
        $rider->updateLocation($location);
        $this->riders->save($rider);

        return $rider;
    }
}
