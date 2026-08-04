<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Infrastructure\Event;

use EruoFood\Loyalty\Application\Service\EventTranslator;
use EruoFood\Shared\Domain\DomainEvent;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Wires the loyalty programme onto the shared event bus. It listens for every
 * earn-rule event plus the referral qualifying event; when a business module
 * publishes one (an order, a review) the listener hands it to the
 * {@see EventTranslator}, which awards points and qualifies referrals. One-way,
 * by event name — no business module imports Loyalty, and none awards points.
 */
final readonly class DomainEventSubscriber
{
    /**
     * @param list<string> $eventNames
     */
    public function __construct(
        private Dispatcher $dispatcher,
        private array $eventNames,
    ) {
    }

    public function register(): void
    {
        foreach (array_unique($this->eventNames) as $eventName) {
            $this->dispatcher->listen($eventName, function (object $event): void {
                if ($event instanceof DomainEvent) {
                    app(EventTranslator::class)->handle($event);
                }
            });
        }
    }
}
