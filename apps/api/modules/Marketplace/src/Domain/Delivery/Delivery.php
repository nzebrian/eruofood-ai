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
    /**
     * The journey, written out.
     *
     * This replaced a `+1` ordinal table in M26, and the replacement was not
     * stylistic. The ordinal table read:
     *
     *     ['unassigned' => 0, 'assigned' => 1, 'picked_up' => 2, 'en_route' => 3, ...]
     *
     * which encodes `en_route` as coming *after* `picked_up` — i.e. en route to
     * the customer. That is a defensible meaning, but nothing said so, and the
     * obvious reading ("en route to the restaurant") is the opposite order. A
     * table of ordinals cannot be read; an explicit table cannot be wrong
     * quietly.
     *
     * The legacy names sit alongside their modern equivalents so existing rows
     * keep advancing: a delivery stored as `assigned` moves on exactly as one
     * stored as `accepted` does.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_NEXT = [
        'unassigned' => ['offered', 'accepted', 'assigned', 'failed'],

        // Nobody answered, or they declined — back to looking.
        'offered' => ['accepted', 'assigned', 'unassigned', 'failed'],

        'accepted' => ['en_route_pickup', 'unassigned', 'failed'],
        'assigned' => ['en_route_pickup', 'picked_up', 'unassigned', 'failed'],

        'en_route_pickup' => ['arrived_pickup', 'unassigned', 'failed'],
        'arrived_pickup' => ['picked_up', 'unassigned', 'failed'],

        // Past this point the rider holds the customer's food. Returning to
        // `unassigned` is no longer a delivery-state change — it is an
        // operational incident, and it goes through `failed`.
        'picked_up' => ['in_transit', 'en_route', 'failed'],
        'in_transit' => ['delivered', 'failed'],
        'en_route' => ['delivered', 'failed'],

        'delivered' => [],
        'failed' => [],
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
            $id,
            $orderId,
            $vendorId,
            null,
            DeliveryStatus::Unassigned,
            $fee,
            $zoneName,
            $pickup,
            $dropoff,
            [],
            null,
            null,
            $now,
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
            $id,
            $orderId,
            $vendorId,
            $riderId,
            $status,
            $fee,
            $zoneName,
            $pickup,
            $dropoff,
            $trackPoints,
            $assignedAt,
            $deliveredAt,
            $createdAt,
        );
    }

    /**
     * A vendor or admin hands the delivery to a rider directly.
     *
     * The pre-M26 manual path, unchanged. It still records `assigned`, and that
     * is deliberate: this endpoint has shipped, clients read `data.status` from
     * it, and quietly changing a live API's output to tidy up an enum would
     * break them for no benefit anybody asked for. `assigned` and `accepted`
     * are the same point in the journey — see {@see DeliveryStatus::canonical()}.
     *
     * M26's dispatch path uses {@see acceptedByRider()} instead.
     */
    public function assignRider(string $riderId, DateTimeImmutable $at): void
    {
        if ($this->status !== DeliveryStatus::Unassigned && $this->status !== DeliveryStatus::Offered) {
            throw new MarketplaceInvalidState('Delivery already has a rider.');
        }

        $this->riderId = $riderId;
        $this->status = DeliveryStatus::Assigned;
        $this->assignedAt = $at;
    }

    /**
     * A rider accepted an M26 dispatch offer.
     *
     * Separate from {@see assignRider()} only so the manual path's shipped
     * output stays exactly as it was; the resulting state is the same point in
     * the journey under its modern name.
     */
    public function acceptedByRider(string $riderId, DateTimeImmutable $at): void
    {
        if ($this->status !== DeliveryStatus::Unassigned && $this->status !== DeliveryStatus::Offered) {
            throw new MarketplaceInvalidState('Delivery already has a rider.');
        }

        $this->riderId = $riderId;
        $this->status = DeliveryStatus::Accepted;
        $this->assignedAt = $at;
    }

    /** A rider is being asked. Not yet theirs. */
    public function markOffered(DateTimeImmutable $at): void
    {
        if ($this->status !== DeliveryStatus::Unassigned) {
            throw new MarketplaceInvalidState(sprintf(
                'Cannot offer a delivery that is "%s".',
                $this->status->value,
            ));
        }

        $this->status = DeliveryStatus::Offered;
    }

    /**
     * Move the delivery along, refusing anything the table does not allow.
     *
     * `failed` is reachable from any non-terminal state — a delivery can go
     * wrong at any point — but not from `delivered`. A completed delivery is
     * finished, and letting it be marked failed afterwards would let a mistake
     * or a bad actor rewrite a customer's completed order.
     */
    public function advanceTo(DeliveryStatus $next, DateTimeImmutable $at): void
    {
        $allowed = self::ALLOWED_NEXT[$this->status->value];

        if (! in_array($next->value, $allowed, true)) {
            throw new MarketplaceInvalidState(sprintf(
                'Cannot move a delivery from "%s" to "%s".',
                $this->status->value,
                $next->value,
            ));
        }

        $this->status = $next;

        if ($next === DeliveryStatus::Unassigned) {
            // Reassignment: the rider is released and the delivery goes back to
            // looking. Keeping the old rider id would leave the record claiming
            // somebody is carrying it who is not.
            $this->riderId = null;
            $this->assignedAt = null;
        }

        if ($next === DeliveryStatus::Delivered) {
            $this->deliveredAt = $at;
        }
    }

    /** Whether this delivery may move to `$next` — asked before offering the transition. */
    public function canAdvanceTo(DeliveryStatus $next): bool
    {
        return in_array($next->value, self::ALLOWED_NEXT[$this->status->value], true);
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
