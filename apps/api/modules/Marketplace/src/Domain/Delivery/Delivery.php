<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\Delivery;

use DateTimeImmutable;
use EruoFood\Marketplace\Domain\Enum\DeliveryStatus;
use EruoFood\Marketplace\Domain\Exception\MarketplaceInvalidState;
use EruoFood\Marketplace\Domain\ValueObject\GeoLocation;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * A delivery job for an order: rider assignment, status progression, and a trail
 * of location breadcrumbs for live tracking. Route optimisation consumes the
 * pickup/dropoff coordinates (see the RouteOptimizer port) — architecture-ready.
 */
final class Delivery
{
    private const ORDER = [
        'unassigned' => 0, 'assigned' => 1, 'picked_up' => 2, 'en_route' => 3, 'delivered' => 4,
    ];

    /**
     * @param list<array{lat: float, lng: float, at: string}> $trackPoints
     */
    private function __construct(
        private readonly string $id,
        private readonly string $orderId,
        private readonly string $vendorId,
        private ?string $riderId,
        private DeliveryStatus $status,
        private readonly Money $fee,
        private readonly ?string $zoneName,
        private readonly ?GeoLocation $pickup,
        private readonly ?GeoLocation $dropoff,
        private array $trackPoints,
        private ?DateTimeImmutable $assignedAt,
        private ?DateTimeImmutable $deliveredAt,
        private readonly DateTimeImmutable $createdAt,
    ) {
    }

    public static function create(
        string $id,
        string $orderId,
        string $vendorId,
        Money $fee,
        ?string $zoneName,
        ?GeoLocation $pickup,
        ?GeoLocation $dropoff,
        DateTimeImmutable $now,
    ): self {
        return new self(
            $id, $orderId, $vendorId, null, DeliveryStatus::Unassigned, $fee, $zoneName,
            $pickup, $dropoff, [], null, null, $now,
        );
    }

    /**
     * @param list<array{lat: float, lng: float, at: string}> $trackPoints
     */
    public static function reconstitute(
        string $id,
        string $orderId,
        string $vendorId,
        ?string $riderId,
        DeliveryStatus $status,
        Money $fee,
        ?string $zoneName,
        ?GeoLocation $pickup,
        ?GeoLocation $dropoff,
        array $trackPoints,
        ?DateTimeImmutable $assignedAt,
        ?DateTimeImmutable $deliveredAt,
        DateTimeImmutable $createdAt,
    ): self {
        return new self(
            $id, $orderId, $vendorId, $riderId, $status, $fee, $zoneName, $pickup,
            $dropoff, array_values($trackPoints), $assignedAt, $deliveredAt, $createdAt,
        );
    }

    public function assignRider(string $riderId, DateTimeImmutable $at): void
    {
        if ($this->status !== DeliveryStatus::Unassigned) {
            throw new MarketplaceInvalidState('Delivery already has a rider.');
        }
        $this->riderId = $riderId;
        $this->status = DeliveryStatus::Assigned;
        $this->assignedAt = $at;
    }

    public function advanceTo(DeliveryStatus $next, DateTimeImmutable $at): void
    {
        if ($next === DeliveryStatus::Failed) {
            $this->status = DeliveryStatus::Failed;

            return;
        }
        $current = self::ORDER[$this->status->value] ?? -1;
        $target = self::ORDER[$next->value] ?? -1;
        if ($target !== $current + 1) {
            throw new MarketplaceInvalidState(sprintf(
                'Cannot move a delivery from "%s" to "%s".',
                $this->status->value,
                $next->value,
            ));
        }
        $this->status = $next;
        if ($next === DeliveryStatus::Delivered) {
            $this->deliveredAt = $at;
        }
    }

    /** Append a live-tracking breadcrumb. */
    public function track(GeoLocation $point, DateTimeImmutable $at): void
    {
        $this->trackPoints[] = ['lat' => $point->latitude, 'lng' => $point->longitude, 'at' => $at->format(DATE_ATOM)];
    }

    public function id(): string
    {
        return $this->id;
    }

    public function orderId(): string
    {
        return $this->orderId;
    }

    public function vendorId(): string
    {
        return $this->vendorId;
    }

    public function riderId(): ?string
    {
        return $this->riderId;
    }

    public function status(): DeliveryStatus
    {
        return $this->status;
    }

    public function fee(): Money
    {
        return $this->fee;
    }

    public function zoneName(): ?string
    {
        return $this->zoneName;
    }

    public function pickup(): ?GeoLocation
    {
        return $this->pickup;
    }

    public function dropoff(): ?GeoLocation
    {
        return $this->dropoff;
    }

    /** @return list<array{lat: float, lng: float, at: string}> */
    public function trackPoints(): array
    {
        return $this->trackPoints;
    }

    public function assignedAt(): ?DateTimeImmutable
    {
        return $this->assignedAt;
    }

    public function deliveredAt(): ?DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
