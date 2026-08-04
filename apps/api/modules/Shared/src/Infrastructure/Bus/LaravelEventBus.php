<?php

declare(strict_types=1);

namespace EruoFood\Shared\Infrastructure\Bus;

use EruoFood\Shared\Domain\DomainEvent;
use EruoFood\Shared\Domain\EventBus;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * EventBus implementation backed by Laravel's event dispatcher.
 *
 * This is the infrastructure adapter for the Shared\Domain\EventBus port.
 * Listeners may be queued to make side effects asynchronous (notifications,
 * analytics, AI enrichment — see MASTER_PLAN.md §3.4).
 */
final readonly class LaravelEventBus implements EventBus
{
    public function __construct(private Dispatcher $dispatcher)
    {
    }

    public function publish(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->dispatcher->dispatch($event->eventName(), $event);
        }
    }
}
