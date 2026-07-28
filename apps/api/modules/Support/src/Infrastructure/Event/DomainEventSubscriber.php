<?php

declare(strict_types=1);

namespace EruoFood\Support\Infrastructure\Event;

use EruoFood\Support\Application\Service\EventTranslator;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Wires the Support CRM onto the shared event bus. It listens for every event in
 * the config timeline-map; when a business module publishes one (a registration,
 * an order, a payment) the listener hands it to the {@see EventTranslator}, which
 * folds it into the customer profile and timeline. One-way, by event name — no
 * business module imports Support, and none manages tickets or the CRM directly.
 */
final readonly class DomainEventSubscriber
{
    /**
     * @param array<string, string> $eventMap
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
                app(EventTranslator::class)->handle($event);
            });
        }
    }
}
