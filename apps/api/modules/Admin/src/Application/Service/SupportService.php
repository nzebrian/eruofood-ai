<?php

declare(strict_types=1);

namespace EruoFood\Admin\Application\Service;

use DateTimeImmutable;
use EruoFood\Admin\Domain\Enum\AuditCategory;
use EruoFood\Admin\Domain\Exception\AdminNotFound;
use EruoFood\Admin\Domain\Support\Ticket;
use EruoFood\Admin\Domain\Support\TicketPriority;
use EruoFood\Admin\Domain\Support\TicketRepository;
use EruoFood\Admin\Domain\Support\TicketStatus;
use EruoFood\Shared\Domain\Paginated;

/**
 * The Support Centre use cases: raising tickets, the live queue, agent
 * assignment, public replies, internal notes, escalation and resolution.
 * Staff actions are audit-logged under the Support category.
 */
final readonly class SupportService
{
    public function __construct(
        private TicketRepository $tickets,
        private AuditService $audit,
    ) {
    }

    public function open(string $requesterId, string $subject, string $category, TicketPriority $priority, string $body): Ticket
    {
        $ticket = Ticket::open(
            $this->tickets->nextIdentity(),
            $requesterId,
            $subject,
            $category,
            $priority,
            $body,
            $this->tickets->nextMessageIdentity(),
            new DateTimeImmutable(),
        );
        $this->tickets->save($ticket);

        return $ticket;
    }

    public function assign(string $actorId, string $id, string $agentId): Ticket
    {
        $ticket = $this->require($id);
        $ticket->assign($agentId, new DateTimeImmutable());
        $this->tickets->save($ticket);
        $this->audit->record($actorId, AuditCategory::Support, 'support.ticket_assigned', 'ticket', $id, ['agent_id' => $agentId]);

        return $ticket;
    }

    public function reply(string $actorId, string $id, string $body): Ticket
    {
        $ticket = $this->require($id);
        $ticket->reply($this->tickets->nextMessageIdentity(), $actorId, $body, new DateTimeImmutable());
        $this->tickets->save($ticket);
        $this->audit->record($actorId, AuditCategory::Support, 'support.ticket_replied', 'ticket', $id);

        return $ticket;
    }

    public function addNote(string $actorId, string $id, string $body): Ticket
    {
        $ticket = $this->require($id);
        $ticket->addInternalNote($this->tickets->nextMessageIdentity(), $actorId, $body, new DateTimeImmutable());
        $this->tickets->save($ticket);
        $this->audit->record($actorId, AuditCategory::Support, 'support.ticket_noted', 'ticket', $id);

        return $ticket;
    }

    public function escalate(string $actorId, string $id, TicketPriority $priority): Ticket
    {
        $ticket = $this->require($id);
        $ticket->escalate($priority, new DateTimeImmutable());
        $this->tickets->save($ticket);
        $this->audit->record($actorId, AuditCategory::Support, 'support.ticket_escalated', 'ticket', $id, ['priority' => $priority->value]);

        return $ticket;
    }

    public function resolve(string $actorId, string $id): Ticket
    {
        $ticket = $this->require($id);
        $ticket->resolve(new DateTimeImmutable());
        $this->tickets->save($ticket);
        $this->audit->record($actorId, AuditCategory::Support, 'support.ticket_resolved', 'ticket', $id);

        return $ticket;
    }

    public function close(string $actorId, string $id): Ticket
    {
        $ticket = $this->require($id);
        $ticket->close(new DateTimeImmutable());
        $this->tickets->save($ticket);
        $this->audit->record($actorId, AuditCategory::Support, 'support.ticket_closed', 'ticket', $id);

        return $ticket;
    }

    /**
     * @return Paginated<Ticket>
     */
    public function queue(?TicketStatus $status, ?string $assigneeId, int $page, int $perPage): Paginated
    {
        return $this->tickets->search($status, $assigneeId, $page, $perPage);
    }

    public function get(string $id): Ticket
    {
        return $this->require($id);
    }

    private function require(string $id): Ticket
    {
        return $this->tickets->findById($id) ?? throw AdminNotFound::of('ticket', $id);
    }
}
