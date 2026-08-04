<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain;

/**
 * Base class for aggregate roots.
 *
 * An aggregate root is the single entry point to a cluster of domain objects
 * and the consistency boundary for its invariants. It records domain events
 * that infrastructure later publishes (see the Transactional Outbox pattern in
 * MASTER_PLAN.md §5.4). Pure PHP — no framework dependencies.
 */
abstract class AggregateRoot
{
    /** @var list<DomainEvent> */
    private array $recordedEvents = [];

    protected function recordThat(DomainEvent $event): void
    {
        $this->recordedEvents[] = $event;
    }

    /**
     * Pull and clear the recorded events (called by the persistence layer
     * after the aggregate is saved).
     *
     * @return list<DomainEvent>
     */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }
}
