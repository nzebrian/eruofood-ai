<?php

declare(strict_types=1);

namespace EruoFood\Support\Application\Service;

use EruoFood\Support\Application\Port\AiSupportAssistant;
use EruoFood\Support\Domain\Exception\SupportNotFound;
use EruoFood\Support\Domain\Ticket\TicketMessage;
use EruoFood\Support\Domain\Ticket\TicketRepository;

/**
 * AI-assisted agent tooling: a summary of the ticket thread and a suggested
 * reply. Reads the ticket and delegates to the {@see AiSupportAssistant} port
 * (offline heuristic by default, AI-backed when enabled).
 */
final readonly class AgentAssistService
{
    public function __construct(
        private TicketRepository $tickets,
        private AiSupportAssistant $assistant,
    ) {
    }

    public function summarise(string $ticketId): string
    {
        $ticket = $this->tickets->findById($ticketId) ?? throw SupportNotFound::of('ticket', $ticketId);

        return $this->assistant->summariseThread($ticket->subject(), $this->thread($ticket->messages()));
    }

    public function suggestReply(string $ticketId): string
    {
        $ticket = $this->tickets->findById($ticketId) ?? throw SupportNotFound::of('ticket', $ticketId);

        return $this->assistant->suggestReply($ticket->subject(), $this->thread($ticket->messages()));
    }

    /**
     * @param list<TicketMessage> $messages
     * @return list<array{author: string, body: string}>
     */
    private function thread(array $messages): array
    {
        return array_values(array_map(
            static fn (TicketMessage $m): array => ['author' => $m->authorType->value, 'body' => $m->body],
            array_values(array_filter($messages, static fn (TicketMessage $m): bool => ! $m->internal)),
        ));
    }
}
