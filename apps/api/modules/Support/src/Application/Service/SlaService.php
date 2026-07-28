<?php

declare(strict_types=1);

namespace EruoFood\Support\Application\Service;

use DateTimeImmutable;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Support\Domain\Event\SlaBreached;
use EruoFood\Support\Domain\Event\TicketEscalated;
use EruoFood\Support\Domain\Ticket\Ticket;
use EruoFood\Support\Domain\Ticket\TicketRepository;
use EruoFood\Support\Domain\Sla\SlaPolicyRepository;

/**
 * SLA management: applies the priority's policy to a ticket (computing its
 * first-response and resolution due times from the ticket's open time), and runs
 * the periodic breach scan. On a resolution breach it publishes {@see SlaBreached}
 * and — when configured — escalates the ticket one priority (which re-applies the
 * new policy, pushing the clock out) so the same ticket is not re-escalated every
 * scan.
 */
final readonly class SlaService
{
    public function __construct(
        private TicketRepository $tickets,
        private SlaPolicyRepository $policies,
        private EventBus $events,
        private bool $escalateOnBreach,
    ) {
    }

    /** Attach the policy for the ticket's current priority, timed from `$from`. */
    public function applyTo(Ticket $ticket, DateTimeImmutable $from): void
    {
        $policy = $this->policies->findByPriority($ticket->priority());
        if ($policy === null) {
            return;
        }
        $ticket->applySla(
            $policy->id(),
            $policy->firstResponseDueAt($from),
            $policy->resolutionDueAt($from),
        );
    }

    /**
     * Scan for tickets past their resolution SLA and act on them. Returns the
     * number of breaches handled.
     */
    public function scanBreaches(int $limit = 100): int
    {
        $now = new DateTimeImmutable();
        $handled = 0;

        foreach ($this->tickets->breachingResolution($now, $limit) as $ticket) {
            $this->events->publish(new SlaBreached($ticket->id(), $ticket->ref(), 'resolution', $ticket->assigneeId()));

            if ($this->escalateOnBreach) {
                $priority = $ticket->escalate($now);
                $ticket->addSystemNote(
                    $this->tickets->nextMessageIdentity(),
                    'SLA resolution breached — escalated to '.$priority->value.'.',
                    $now,
                );
                $this->applyTo($ticket, $ticket->createdAt());
                $this->events->publish(new TicketEscalated($ticket->id(), $ticket->ref(), $priority->value, 'sla_breach', $ticket->assigneeId()));
            }

            $this->tickets->save($ticket);
            $handled++;
        }

        return $handled;
    }
}
