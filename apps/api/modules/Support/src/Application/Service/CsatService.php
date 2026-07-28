<?php

declare(strict_types=1);

namespace EruoFood\Support\Application\Service;

use DateTimeImmutable;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Support\Domain\Csat\CsatResponse;
use EruoFood\Support\Domain\Csat\CsatRepository;
use EruoFood\Support\Domain\Csat\CsatSummary;
use EruoFood\Support\Domain\Event\CsatSubmitted;
use EruoFood\Support\Domain\Exception\SupportNotAuthorized;
use EruoFood\Support\Domain\Exception\SupportNotFound;
use EruoFood\Support\Domain\Ticket\TicketRepository;

/**
 * Customer-satisfaction surveys: records the 1–5 CSAT a customer gives on a
 * resolved ticket (once), and serves the satisfaction dashboard summary.
 */
final readonly class CsatService
{
    public function __construct(
        private CsatRepository $csat,
        private TicketRepository $tickets,
        private EventBus $events,
    ) {
    }

    public function submit(string $ticketId, string $requesterId, int $score, ?string $comment): CsatResponse
    {
        $ticket = $this->tickets->findById($ticketId) ?? throw SupportNotFound::of('ticket', $ticketId);
        if ($ticket->requesterId() !== $requesterId) {
            throw new SupportNotAuthorized('You may only rate your own tickets.');
        }

        $ticket->recordCsat($score); // validates terminal state + range
        $this->tickets->save($ticket);

        $response = new CsatResponse(
            $this->csat->nextIdentity(),
            $ticketId,
            $requesterId,
            $score,
            $comment,
            $ticket->assigneeId(),
            new DateTimeImmutable(),
        );
        $this->csat->save($response);
        $this->events->publish(new CsatSubmitted($ticketId, $requesterId, $score));

        return $response;
    }

    public function summary(int $days): CsatSummary
    {
        return $this->csat->summary($days);
    }
}
