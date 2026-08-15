<?php

declare(strict_types=1);

namespace EruoFood\Dispatch\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * A rider said no.
 *
 * Not a failure — declining is a normal thing a rider does, and the request
 * simply looks for somebody else. The event states what happened rather than
 * instructing anybody what to do next, so a future consumer counting decline
 * rates does not become something the dispatch loop depends on.
 */
final readonly class OfferDeclined implements DomainEvent
{
    public function __construct(
        public string $offerId,
        public string $requestId,
        public string $riderId,
        public ?string $reason,
        private DateTimeImmutable $at,
    ) {
    }

    public function eventName(): string
    {
        return 'dispatch.offer_declined';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->at;
    }
}
