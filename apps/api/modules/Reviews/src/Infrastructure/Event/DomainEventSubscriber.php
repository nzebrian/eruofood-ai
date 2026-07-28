<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Infrastructure\Event;

use EruoFood\Reviews\Application\Service\EventTranslator;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Wires the verified-purchase ledger onto the shared event bus. It listens for
 * every order/interaction event in the config eligibility-map; when a business
 * module publishes one the listener hands it to the {@see EventTranslator}, which
 * records (buyer, subject) eligibility. One-way, by event name — no business
 * module imports Reviews, and none stores or aggregates reviews directly.
 */
final readonly class DomainEventSubscriber
{
    /**
     * @param array<string, array{subject_type: string, subject_field: string, user_field: string}> $eligibilityMap
     */
    public function __construct(
        private Dispatcher $dispatcher,
        private array $eligibilityMap,
    ) {
    }

    public function register(): void
    {
        foreach (array_keys($this->eligibilityMap) as $eventName) {
            $this->dispatcher->listen($eventName, function (object $event): void {
                app(EventTranslator::class)->handle($event);
            });
        }
    }
}
