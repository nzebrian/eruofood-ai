<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * Nobody answered before the TTL ran out.
 *
 * Told apart from a decline on purpose: a rider who declined made a choice, one
 * who timed out may have had no signal, and treating the two the same would
 * penalise the second for their network.
 */
final readonly class OfferExpired implements DomainEvent
{
    public function __construct(
        public string $offerId,
        public string $requestId,
        public string $riderId,
        private DateTimeImmutable $at,
    ) {
    }

    public function eventName(): string
    {
        return 'dispatch.offer_expired';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->at;
    }
}
