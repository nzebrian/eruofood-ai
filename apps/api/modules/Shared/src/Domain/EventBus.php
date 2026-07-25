<?php

declare(strict_types=1);

namespace EruoFood\Shared\Domain;

/**
 * Port for publishing domain events.
 *
 * The application layer depends on this abstraction; the infrastructure layer
 * provides an implementation (e.g. backed by Laravel's event dispatcher and
 * queue). This inverts the dependency so the domain never knows about the
 * framework (Dependency Inversion Principle).
 */
interface EventBus
{
    public function publish(DomainEvent ...$events): void;
}
