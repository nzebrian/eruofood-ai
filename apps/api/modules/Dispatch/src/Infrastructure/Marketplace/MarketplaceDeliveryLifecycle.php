<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Infrastructure\Marketplace;

use EruoFood\Dispatch\Application\Port\DeliveryLifecycle;
use EruoFood\Marketplace\Domain\Delivery\DeliveryRepository;
use EruoFood\Marketplace\Domain\Enum\DeliveryStatus;
use EruoFood\Marketplace\Domain\Exception\MarketplaceInvalidState;
use EruoFood\Shared\Domain\Clock;

/**
 * The one place Dispatch reaches into Marketplace.
 *
 * A single adapter, deliberately. Marketplace publishes no contracts package,
 * so this class imports its repository and enum directly — and containing that
 * to one file is the point: Marketplace can restructure its aggregate freely
 * and only this has to follow. Every other class in Dispatch talks to
 * {@see DeliveryLifecycle}, which names none of it.
 *
 * ## Refusals are returned, not thrown
 *
 * Marketplace is the authority on the delivery's journey (M26 decision 1). When
 * it refuses a transition, that is Marketplace exercising its authority, not an
 * error in Dispatch — so the caller is told `false` and leaves its own record
 * alone. Throwing would make Dispatch's transaction fail over a decision that
 * was correctly made by the context that owns it.
 */
final readonly class MarketplaceDeliveryLifecycle implements DeliveryLifecycle
{
    public function __construct(
        private DeliveryRepository $deliveries,
        private Clock $clock,
    ) {
    }

    public function statusOf(string $deliveryId): ?string
    {
        return $this->deliveries->findById($deliveryId)?->status()->value;
    }

    public function markOffered(string $deliveryId): bool
    {
        $delivery = $this->deliveries->findById($deliveryId);

        if ($delivery === null) {
            return false;
        }

        try {
            $delivery->markOffered($this->clock->now());
        } catch (MarketplaceInvalidState) {
            return false;
        }

        $this->deliveries->save($delivery);

        return true;
    }

    public function riderAccepted(string $deliveryId, string $riderId): bool
    {
        $delivery = $this->deliveries->findById($deliveryId);

        if ($delivery === null) {
            return false;
        }

        try {
            $delivery->acceptedByRider($riderId, $this->clock->now());
        } catch (MarketplaceInvalidState) {
            return false;
        }

        $this->deliveries->save($delivery);

        return true;
    }

    public function advance(string $deliveryId, string $status): bool
    {
        $delivery = $this->deliveries->findById($deliveryId);
        $next = DeliveryStatus::tryFrom($status);

        if ($delivery === null || $next === null) {
            return false;
        }

        // Already there. Not a failure — a rider whose phone retried a status
        // update should not be told they did something wrong.
        if ($delivery->status()->canonical() === $next->canonical()) {
            return true;
        }

        try {
            $delivery->advanceTo($next, $this->clock->now());
        } catch (MarketplaceInvalidState) {
            return false;
        }

        $this->deliveries->save($delivery);

        return true;
    }

    public function releaseRider(string $deliveryId): bool
    {
        return $this->advance($deliveryId, DeliveryStatus::Unassigned->value);
    }
}
