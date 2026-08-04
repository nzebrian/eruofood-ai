<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain;

use DateTimeImmutable;

/**
 * Marker contract for all domain events.
 *
 * Domain events are immutable facts about something that happened in the domain.
 * They carry no framework dependencies and are dispatched through the EventBus,
 * enabling loose coupling between bounded contexts (Modular Monolith rule:
 * modules communicate via contracts and events, never internals).
 */
interface DomainEvent
{
    /** A stable, human-readable event name, e.g. "platform.health_checked". */
    public function eventName(): string;

    /** The moment the event occurred. */
    public function occurredAt(): DateTimeImmutable;
}
