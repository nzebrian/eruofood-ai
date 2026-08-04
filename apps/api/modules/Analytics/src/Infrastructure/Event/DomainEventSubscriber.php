<?php

declare(strict_types=1);

namespace EruoFood\Analytics\Infrastructure\Event;

use EruoFood\Analytics\Application\Service\EventTranslator;
use EruoFood\Shared\Domain\DomainEvent;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Wires the Analytics context onto the shared event bus. It registers a listener
 * for every event name in the config event-map; when a business module publishes
 * one, the listener hands the event to the {@see EventTranslator}, which collects
 * it into analytics. This is the only inbound coupling — one-way and by event
 * name — so no module ever writes into analytics.
 */
final readonly class DomainEventSubscriber
{
    /**
     * @param array<string, mixed> $eventMap
     */
    public function __construct(
        private Dispatcher $dispatcher,
        private array $eventMap,
    ) {
    }

    public function register(): void
    {
        foreach (array_keys($this->eventMap) as $eventName) {
            $this->dispatcher->listen($eventName, function (object $event): void {
                if ($event instanceof DomainEvent) {
                    app(EventTranslator::class)->handle($event);
                }
            });
        }
    }
}
