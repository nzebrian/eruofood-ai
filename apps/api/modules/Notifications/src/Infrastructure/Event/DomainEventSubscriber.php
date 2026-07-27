<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Infrastructure\Event;

use EruoFood\Notifications\Application\Service\EventTranslator;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Wires the Notifications context onto the shared event bus. It registers a
 * listener for every event name in the config event-map; when a business module
 * publishes one (via the EventBus, which dispatches by `eventName()`), the
 * listener hands the event to the {@see EventTranslator}, which turns it into
 * notifications. This is the *only* coupling point, and it is one-way and by
 * event name — no business module imports or calls the notification engine.
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
                app(EventTranslator::class)->handle($event);
            });
        }
    }
}
