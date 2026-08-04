<?php

declare(strict_types=1);

namespace EruoFood\Admin\Infrastructure\Event;

use EruoFood\Admin\Application\Service\EventAuditTranslator;
use EruoFood\Shared\Domain\DomainEvent;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Wires the Admin audit trail onto the shared event bus. It registers a
 * listener for every event name in the config audit-map; when a business module
 * publishes one (via the EventBus, which dispatches by `eventName()`), the
 * listener hands the event to the {@see EventAuditTranslator}, which appends an
 * audit entry. One-way, by event name — no business module imports Admin.
 */
final readonly class DomainEventSubscriber
{
    /**
     * @param array<string, string> $auditMap
     */
    public function __construct(
        private Dispatcher $dispatcher,
        private array $auditMap,
    ) {
    }

    public function register(): void
    {
        foreach (array_keys($this->auditMap) as $eventName) {
            $this->dispatcher->listen($eventName, function (object $event): void {
                if ($event instanceof DomainEvent) {
                    app(EventAuditTranslator::class)->handle($event);
                }
            });
        }
    }
}
