<?php

declare(strict_types=1);

namespace EruoFood\Search\Infrastructure\Event;

use EruoFood\Search\Application\Service\EventIndexTranslator;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Wires the Search index onto the shared event bus. It listens for every event
 * name in the config index-map; when a business module publishes one (a food
 * published, a vendor verified, …) the listener hands it to the
 * {@see EventIndexTranslator}, which reindexes the affected document. One-way,
 * by event name — no business module imports Search, and none searches directly.
 */
final readonly class DomainEventSubscriber
{
    /**
     * @param array<string, array{type: string, id_field: string}> $eventMap
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
                app(EventIndexTranslator::class)->handle($event);
            });
        }
    }
}
