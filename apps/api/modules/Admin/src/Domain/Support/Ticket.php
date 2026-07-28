<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Support;

use DateTimeImmutable;
use EruoFood\Admin\Domain\Exception\AdminInvalidState;

/**
 * A customer-support ticket — the aggregate root of the Support Centre. It owns
 * its conversation of {@see TicketMessage} entities (public replies + internal
 * notes), its status/priority, and its assignment to a support agent. The
 * requester is an Identity user, referenced by id only.
 */
final class Ticket
{
    /**
     * @param list<TicketMessage> $messages
     */
    private function __construct(
        private readonly string $id,
        private readonly string $requesterId,
        private string $subject,
        private readonly string $category,
        private TicketStatus $status,
        private TicketPriority $priority,
        private ?string $assigneeId,
        private array $messages,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    public static function open(
        string $id,
        string $requesterId,
        string $subject,
        string $category,
        TicketPriority $priority,
        string $body,
        string $firstMessageId,
        DateTimeImmutable $now,
    ): self {
        $ticket = new self(
            $id,
            $requesterId,
            $subject,
            $category,
            TicketStatus::Open,
            $priority,
            null,
            [],
            $now,
            $now,
        );
        $ticket->messages[] = new TicketMessage($firstMessageId, $requesterId, $body, false, $now);

        return $ticket;
    }

    /**
     * @param list<TicketMessage> $messages
     */
    public static function reconstitute(
        string $id,
        string $requesterId,
        string $subject,
        string $category,
        TicketStatus $status,
        TicketPriority $priority,
        ?string $assigneeId,
        array $messages,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self($id, $requesterId, $subject, $category, $status, $priority, $assigneeId, $messages, $createdAt, $updatedAt);
    }

    public function assign(string $agentId, DateTimeImmutable $now): void
    {
        $this->assigneeId = $agentId;
        if ($this->status === TicketStatus::Open) {
            $this->status = TicketStatus::Pending;
        }
        $this->updatedAt = $now;
    }

    public function escalate(TicketPriority $priority, DateTimeImmutable $now): void
    {
        $this->priority = $priority;
        $this->updatedAt = $now;
    }

    public function reply(string $messageId, string $authorId, string $body, DateTimeImmutable $now): void
    {
        $this->guardOpen();
        $this->messages[] = new TicketMessage($messageId, $authorId, $body, false, $now);
        $this->updatedAt = $now;
    }

    public function addInternalNote(string $messageId, string $authorId, string $body, DateTimeImmutable $now): void
    {
        $this->guardOpen();
        $this->messages[] = new TicketMessage($messageId, $authorId, $body, true, $now);
        $this->updatedAt = $now;
    }

    public function resolve(DateTimeImmutable $now): void
    {
        $this->guardOpen();
        $this->status = TicketStatus::Resolved;
        $this->updatedAt = $now;
    }

    public function close(DateTimeImmutable $now): void
    {
        $this->status = TicketStatus::Closed;
        $this->updatedAt = $now;
    }

    public function reopen(DateTimeImmutable $now): void
    {
        if ($this->status !== TicketStatus::Resolved && $this->status !== TicketStatus::Closed) {
            throw new AdminInvalidState('Only a resolved or closed ticket can be reopened.');
        }
        $this->status = TicketStatus::Open;
        $this->updatedAt = $now;
    }

    private function guardOpen(): void
    {
        if ($this->status === TicketStatus::Closed) {
            throw new AdminInvalidState('This ticket is closed.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function requesterId(): string
    {
        return $this->requesterId;
    }

    public function subject(): string
    {
        return $this->subject;
    }

    public function category(): string
    {
        return $this->category;
    }

    public function status(): TicketStatus
    {
        return $this->status;
    }

    public function priority(): TicketPriority
    {
        return $this->priority;
    }

    public function assigneeId(): ?string
    {
        return $this->assigneeId;
    }

    /** @return list<TicketMessage> */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * The messages visible to the requester (public replies only).
     *
     * @return list<TicketMessage>
     */
    public function publicMessages(): array
    {
        return array_values(array_filter($this->messages, static fn (TicketMessage $m): bool => ! $m->internal));
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
