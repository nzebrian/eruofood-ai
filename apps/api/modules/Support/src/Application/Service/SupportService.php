<?php

declare(strict_types=1);

namespace EruoFood\Support\Application\Service;

use DateTimeImmutable;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Support\Domain\Enum\TicketChannel;
use EruoFood\Support\Domain\Enum\TicketPriority;
use EruoFood\Support\Domain\Enum\TicketStatus;
use EruoFood\Support\Domain\Event\TicketEscalated;
use EruoFood\Support\Domain\Event\TicketOpened;
use EruoFood\Support\Domain\Event\TicketReplied;
use EruoFood\Support\Domain\Event\TicketResolved;
use EruoFood\Support\Domain\Exception\SupportNotAuthorized;
use EruoFood\Support\Domain\Exception\SupportNotFound;
use EruoFood\Support\Domain\Ticket\Ticket;
use EruoFood\Support\Domain\Ticket\TicketQuery;
use EruoFood\Support\Domain\Ticket\TicketRepository;
use EruoFood\Support\Domain\ValueObject\Attachment;

/**
 * The one entry point for every support interaction — no business module manages
 * tickets directly. It orchestrates the ticket aggregate with SLA application,
 * the automation engine (auto-routing on open) and the CRM projection, and
 * publishes domain events (opened/replied/resolved/escalated) that Notifications
 * and Analytics react to. HTTP concerns and agent-vs-customer authorisation are
 * handled by the interface layer; ownership is enforced here for customer actions.
 */
final readonly class SupportService
{
    public function __construct(
        private TicketRepository $tickets,
        private SlaService $sla,
        private AutomationEngine $automation,
        private CrmService $crm,
        private EventBus $events,
    ) {
    }

    /**
     * @param list<Attachment> $attachments
     */
    public function open(
        string $requesterId,
        string $subject,
        string $category,
        TicketChannel $channel,
        TicketPriority $priority,
        string $body,
        array $attachments = [],
        ?string $relatedOrderId = null,
    ): Ticket {
        $now = new DateTimeImmutable();
        $ticket = Ticket::open(
            $this->tickets->nextIdentity(),
            $this->tickets->nextReference(),
            $requesterId,
            $subject,
            $category,
            $channel,
            $priority,
            $this->tickets->nextMessageIdentity(),
            $body,
            $attachments,
            $relatedOrderId,
            $now,
        );

        $this->sla->applyTo($ticket, $now);

        $context = ['priority' => $priority->value, 'category' => $category, 'channel' => $channel->value];
        if ($this->automation->run('ticket_opened', $context, $ticket, $now)) {
            $this->sla->applyTo($ticket, $ticket->createdAt());
        }

        $this->tickets->save($ticket);
        $this->crm->onTicketOpened($requesterId, $subject, $ticket->ref());
        $this->events->publish(new TicketOpened($ticket->id(), $ticket->ref(), $requesterId, $subject, $priority->value));

        return $ticket;
    }

    public function assign(string $ticketId, string $agentId): Ticket
    {
        $ticket = $this->require($ticketId);
        $ticket->assign($agentId, new DateTimeImmutable());
        $this->tickets->save($ticket);

        return $ticket;
    }

    /**
     * @param list<Attachment> $attachments
     */
    public function agentReply(string $ticketId, string $agentId, string $body, array $attachments = []): Ticket
    {
        $ticket = $this->require($ticketId);
        $ticket->agentReply($this->tickets->nextMessageIdentity(), $agentId, $body, $attachments, new DateTimeImmutable());
        $this->tickets->save($ticket);
        $this->events->publish(new TicketReplied($ticket->id(), $ticket->ref(), $ticket->requesterId(), $agentId));

        return $ticket;
    }

    /**
     * @param list<Attachment> $attachments
     */
    public function customerReply(string $ticketId, string $requesterId, string $body, array $attachments = []): Ticket
    {
        $ticket = $this->requireOwned($ticketId, $requesterId);
        $ticket->customerReply($this->tickets->nextMessageIdentity(), $body, $attachments, new DateTimeImmutable());
        $this->tickets->save($ticket);

        return $ticket;
    }

    public function internalNote(string $ticketId, string $agentId, string $body): Ticket
    {
        $ticket = $this->require($ticketId);
        $ticket->addInternalNote($this->tickets->nextMessageIdentity(), $agentId, $body, new DateTimeImmutable());
        $this->tickets->save($ticket);

        return $ticket;
    }

    public function changeStatus(string $ticketId, TicketStatus $status): Ticket
    {
        $ticket = $this->require($ticketId);
        $ticket->changeStatus($status, new DateTimeImmutable());
        $this->tickets->save($ticket);
        if ($status === TicketStatus::Resolved) {
            $this->events->publish(new TicketResolved($ticket->id(), $ticket->ref(), $ticket->requesterId()));
        }

        return $ticket;
    }

    public function changePriority(string $ticketId, TicketPriority $priority): Ticket
    {
        $ticket = $this->require($ticketId);
        $ticket->changePriority($priority, new DateTimeImmutable());
        $this->sla->applyTo($ticket, $ticket->createdAt());
        $this->tickets->save($ticket);

        return $ticket;
    }

    public function escalate(string $ticketId, string $reason): Ticket
    {
        $ticket = $this->require($ticketId);
        $now = new DateTimeImmutable();
        $priority = $ticket->escalate($now);
        $ticket->addSystemNote($this->tickets->nextMessageIdentity(), 'Escalated to '.$priority->value.' — '.$reason, $now);
        $this->sla->applyTo($ticket, $ticket->createdAt());
        $this->tickets->save($ticket);
        $this->events->publish(new TicketEscalated($ticket->id(), $ticket->ref(), $priority->value, $reason, $ticket->assigneeId()));

        return $ticket;
    }

    public function merge(string $sourceTicketId, string $targetTicketId): Ticket
    {
        $target = $this->require($targetTicketId); // validate target exists
        $source = $this->require($sourceTicketId);
        $source->mergeInto($target->id(), $this->tickets->nextMessageIdentity(), new DateTimeImmutable());
        $this->tickets->save($source);

        return $source;
    }

    public function addTag(string $ticketId, string $tag): Ticket
    {
        $ticket = $this->require($ticketId);
        $ticket->addTag($tag, new DateTimeImmutable());
        $this->tickets->save($ticket);

        return $ticket;
    }

    /**
     * @return Paginated<Ticket>
     */
    public function queue(TicketQuery $query): Paginated
    {
        return $this->tickets->search($query);
    }

    public function get(string $ticketId): Ticket
    {
        return $this->require($ticketId);
    }

    public function getForCustomer(string $ticketId, string $requesterId): Ticket
    {
        return $this->requireOwned($ticketId, $requesterId);
    }

    private function require(string $ticketId): Ticket
    {
        return $this->tickets->findById($ticketId) ?? throw SupportNotFound::of('ticket', $ticketId);
    }

    private function requireOwned(string $ticketId, string $requesterId): Ticket
    {
        $ticket = $this->require($ticketId);
        if ($ticket->requesterId() !== $requesterId) {
            throw new SupportNotAuthorized('You may only access your own tickets.');
        }

        return $ticket;
    }
}
