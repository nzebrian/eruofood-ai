<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * A delivery was offered to a rider, and the clock is running.
 *
 * What the push notification hangs off. The TTL is short, so a subscriber that
 * is slow to deliver the notification is spending the rider\'s answering time —
 * which is why the expiry instant travels with the event rather than the
 * remaining seconds.
 */
final readonly class OfferMade implements DomainEvent
{
    public function __construct(
        public string $offerId,
        public string $requestId,
        public string $riderId,
        public string $deliveryId,
        public string $expiresAt,
        private DateTimeImmutable $at,
    ) {
    }

    public function eventName(): string
    {
        return 'dispatch.offer_made';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->at;
    }
}
