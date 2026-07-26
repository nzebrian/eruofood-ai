<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\Rider;

use DateTimeImmutable;
use EruoFood\Marketplace\Domain\Enum\RiderStatus;
use EruoFood\Marketplace\Domain\ValueObject\GeoLocation;

/** A delivery rider: availability and last-known location for assignment/tracking. */
final class Rider
{
    private function __construct(
        private readonly string $id,
        private readonly string $userId,
        private string $name,
        private string $phone,
        private string $vehicleType,
        private RiderStatus $status,
        private ?GeoLocation $location,
        private readonly DateTimeImmutable $createdAt,
    ) {
    }

    public static function onboard(
        string $id,
        string $userId,
        string $name,
        string $phone,
        string $vehicleType,
        DateTimeImmutable $now,
    ): self {
        return new self($id, $userId, $name, $phone, $vehicleType, RiderStatus::Offline, null, $now);
    }

    public static function reconstitute(
        string $id,
        string $userId,
        string $name,
        string $phone,
        string $vehicleType,
        RiderStatus $status,
        ?GeoLocation $location,
        DateTimeImmutable $createdAt,
    ): self {
        return new self($id, $userId, $name, $phone, $vehicleType, $status, $location, $createdAt);
    }

    public function setStatus(RiderStatus $status): void
    {
        $this->status = $status;
    }

    public function updateLocation(GeoLocation $location): void
    {
        $this->location = $location;
    }

    public function isAvailable(): bool
    {
        return $this->status === RiderStatus::Available;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function phone(): string
    {
        return $this->phone;
    }

    public function vehicleType(): string
    {
        return $this->vehicleType;
    }

    public function status(): RiderStatus
    {
        return $this->status;
    }

    public function location(): ?GeoLocation
    {
        return $this->location;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
