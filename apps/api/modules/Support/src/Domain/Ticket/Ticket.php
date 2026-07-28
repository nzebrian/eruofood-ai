<?php

declare(strict_types=1);

namespace EruoFood\Support\Domain\Ticket;

use DateTimeImmutable;
use EruoFood\Support\Domain\Enum\MessageAuthorType;
use EruoFood\Support\Domain\Enum\TicketChannel;
use EruoFood\Support\Domain\Enum\TicketPriority;
use EruoFood\Support\Domain\Enum\TicketStatus;
use EruoFood\Support\Domain\Exception\SupportInvalidState;
use EruoFood\Support\Domain\Sla\SlaStatus;
use EruoFood\Support\Domain\ValueObject\Attachment;

/**
 * The aggregate root of the helpdesk: a support ticket with its conversation,
 * assignment, priority, status workflow, SLA clocks and CSAT. It enforces the
 * legal status transitions ({@see TicketStatus::canTransitionTo()}), tracks the
 * first-response and resolution milestones the SLA is measured against, and
 * supports escalation and merge. The requester and assignee are Identity users,
 * referenced by id (soft references).
 */
final class Ticket
{
    /**
     * @param list<TicketMessage> $messages
     * @param list<string> $tags
     */
    private function __construct(
        private readonly string $id,
        private readonly string $ref,
        private readonly string $requesterId,
        private string $subject,
        private string $category,
        private readonly TicketChannel $channel,
        private TicketStatus $status,
        private TicketPriority $priority,
        private ?string $assigneeId,
        private ?string $slaPolicyId,
        private ?DateTimeImmutable $firstResponseDueAt,
        private ?DateTimeImmutable $resolutionDueAt,
        private ?DateTimeImmutable $firstRespondedAt,
        private ?DateTimeImmutable $resolvedAt,
        private ?DateTimeImmutable $closedAt,
        private array $tags,
        private readonly ?string $relatedOrderId,
        private ?string $mergedIntoId,
        private ?int $csatScore,
        private array $messages,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * @param list<Attachment> $attachments
     */
    public static function open(
        string $id,
        string $ref,
        string $requesterId,
        string $subject,
        string $category,
        TicketChannel $channel,
        TicketPriority $priority,
        string $firstMessageId,
        string $body,
        array $attachments,
        ?string $relatedOrderId,
        DateTimeImmutable $now,
    ): self {
        $ticket = new self(
            $id, $ref, $requesterId, $subject, $category, $channel,
            TicketStatus::New, $priority, null, null, null, null, null, null, null,
            [], $relatedOrderId, null, null, [], $now, $now,
        );
        $ticket->messages[] = new TicketMessage(
            $firstMessageId, MessageAuthorType::Customer, $requesterId, $body, false, $attachments, $now,
        );

        return $ticket;
    }

    /**
     * @param list<TicketMessage> $messages
     * @param list<string> $tags
     */
    public static function reconstitute(
        string $id,
        string $ref,
        string $requesterId,
        string $subject,
        string $category,
        TicketChannel $channel,
        TicketStatus $status,
        TicketPriority $priority,
        ?string $assigneeId,
        ?string $slaPolicyId,
        ?DateTimeImmutable $firstResponseDueAt,
        ?DateTimeImmutable $resolutionDueAt,
        ?DateTimeImmutable $firstRespondedAt,
        ?DateTimeImmutable $resolvedAt,
        ?DateTimeImmutable $closedAt,
        array $tags,
        ?string $relatedOrderId,
        ?string $mergedIntoId,
        ?int $csatScore,
        array $messages,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            $id, $ref, $requesterId, $subject, $category, $channel, $status, $priority,
            $assigneeId, $slaPolicyId, $firstResponseDueAt, $resolutionDueAt, $firstRespondedAt,
            $resolvedAt, $closedAt, $tags, $relatedOrderId, $mergedIntoId, $csatScore,
            $messages, $createdAt, $updatedAt,
        );
    }

    /** Attach the resolved SLA policy and its due times (set at open / re-priority). */
    public function applySla(string $policyId, DateTimeImmutable $firstResponseDueAt, DateTimeImmutable $resolutionDueAt): void
    {
        $this->slaPolicyId = $policyId;
        $this->firstResponseDueAt = $firstResponseDueAt;
        $this->resolutionDueAt = $resolutionDueAt;
    }

    public function assign(string $agentId, DateTimeImmutable $now): void
    {
        $this->guardNotMerged();
        $this->assigneeId = $agentId;
        if ($this->status === TicketStatus::New) {
            $this->status = TicketStatus::Open;
        }
        $this->updatedAt = $now;
    }

    /**
     * @param list<Attachment> $attachments
     */
    public function agentReply(string $messageId, string $agentId, string $body, array $attachments, DateTimeImmutable $now): void
    {
        $this->guardActionable();
        $this->messages[] = new TicketMessage($messageId, MessageAuthorType::Agent, $agentId, $body, false, $attachments, $now);
        $this->firstRespondedAt ??= $now;
        if ($this->status === TicketStatus::New) {
            $this->status = TicketStatus::Open;
        }
        $this->updatedAt = $now;
    }

    /**
     * @param list<Attachment> $attachments
     */
    public function customerReply(string $messageId, string $body, array $attachments, DateTimeImmutable $now): void
    {
        $this->guardNotMerged();
        if ($this->status === TicketStatus::Closed) {
            throw new SupportInvalidState('This ticket is closed. Please open a new ticket.');
        }
        $this->messages[] = new TicketMessage($messageId, MessageAuthorType::Customer, $this->requesterId, $body, false, $attachments, $now);
        if ($this->status === TicketStatus::Resolved) {
            $this->status = TicketStatus::Open; // customer response reopens
            $this->resolvedAt = null;
        }
        $this->updatedAt = $now;
    }

    public function addInternalNote(string $messageId, string $agentId, string $body, DateTimeImmutable $now): void
    {
        $this->guardNotMerged();
        $this->messages[] = new TicketMessage($messageId, MessageAuthorType::Agent, $agentId, $body, true, [], $now);
        $this->updatedAt = $now;
    }

    public function addSystemNote(string $messageId, string $body, DateTimeImmutable $now): void
    {
        $this->messages[] = new TicketMessage($messageId, MessageAuthorType::System, null, $body, true, [], $now);
        $this->updatedAt = $now;
    }

    public function addBotReply(string $messageId, string $body, DateTimeImmutable $now): void
    {
        $this->guardActionable();
        $this->messages[] = new TicketMessage($messageId, MessageAuthorType::Bot, null, $body, false, [], $now);
        $this->firstRespondedAt ??= $now;
        $this->updatedAt = $now;
    }

    public function changeStatus(TicketStatus $target, DateTimeImmutable $now): void
    {
        $this->guardNotMerged();
        if (! $this->status->canTransitionTo($target)) {
            throw new SupportInvalidState(sprintf('Cannot move a ticket from %s to %s.', $this->status->value, $target->value));
        }
        if ($target === TicketStatus::Resolved) {
            $this->resolvedAt ??= $now;
        }
        if ($target === TicketStatus::Closed) {
            $this->closedAt ??= $now;
        }
        if ($target === TicketStatus::Open) {
            // Reopening clears the resolution milestone so SLA re-applies.
            $this->resolvedAt = null;
            $this->closedAt = null;
        }
        $this->status = $target;
        $this->updatedAt = $now;
    }

    public function changePriority(TicketPriority $priority, DateTimeImmutable $now): void
    {
        $this->priority = $priority;
        $this->updatedAt = $now;
    }

    /** Bump priority one level (SLA is recomputed by the service). */
    public function escalate(DateTimeImmutable $now): TicketPriority
    {
        $this->priority = $this->priority->escalated();
        $this->updatedAt = $now;

        return $this->priority;
    }

    public function mergeInto(string $targetTicketId, string $systemMessageId, DateTimeImmutable $now): void
    {
        $this->guardNotMerged();
        if ($targetTicketId === $this->id) {
            throw new SupportInvalidState('A ticket cannot be merged into itself.');
        }
        $this->mergedIntoId = $targetTicketId;
        $this->messages[] = new TicketMessage($systemMessageId, MessageAuthorType::System, null, 'Merged into '.$targetTicketId, true, [], $now);
        $this->status = TicketStatus::Closed;
        $this->closedAt ??= $now;
        $this->updatedAt = $now;
    }

    public function recordCsat(int $score): void
    {
        if ($score < 1 || $score > 5) {
            throw new SupportInvalidState('CSAT score must be between 1 and 5.');
        }
        if (! $this->status->isTerminal()) {
            throw new SupportInvalidState('CSAT can only be recorded on a resolved or closed ticket.');
        }
        $this->csatScore = $score;
    }

    public function addTag(string $tag, DateTimeImmutable $now): void
    {
        if (! in_array($tag, $this->tags, true)) {
            $this->tags[] = $tag;
            $this->updatedAt = $now;
        }
    }

    public function slaStatus(DateTimeImmutable $now): SlaStatus
    {
        return SlaStatus::evaluate(
            $this->firstResponseDueAt,
            $this->resolutionDueAt,
            $this->firstRespondedAt,
            $this->resolvedAt,
            $now,
        );
    }

    public function isMerged(): bool
    {
        return $this->mergedIntoId !== null;
    }

    private function guardNotMerged(): void
    {
        if ($this->isMerged()) {
            throw new SupportInvalidState('This ticket has been merged and is read-only.');
        }
    }

    private function guardActionable(): void
    {
        $this->guardNotMerged();
        if ($this->status === TicketStatus::Closed) {
            throw new SupportInvalidState('This ticket is closed.');
        }
    }

    // ---- getters ---------------------------------------------------------

    public function id(): string
    {
        return $this->id;
    }

    public function ref(): string
    {
        return $this->ref;
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

    public function channel(): TicketChannel
    {
        return $this->channel;
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

    public function slaPolicyId(): ?string
    {
        return $this->slaPolicyId;
    }

    public function firstResponseDueAt(): ?DateTimeImmutable
    {
        return $this->firstResponseDueAt;
    }

    public function resolutionDueAt(): ?DateTimeImmutable
    {
        return $this->resolutionDueAt;
    }

    public function firstRespondedAt(): ?DateTimeImmutable
    {
        return $this->firstRespondedAt;
    }

    public function resolvedAt(): ?DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function closedAt(): ?DateTimeImmutable
    {
        return $this->closedAt;
    }

    /** @return list<string> */
    public function tags(): array
    {
        return $this->tags;
    }

    public function relatedOrderId(): ?string
    {
        return $this->relatedOrderId;
    }

    public function mergedIntoId(): ?string
    {
        return $this->mergedIntoId;
    }

    public function csatScore(): ?int
    {
        return $this->csatScore;
    }

    /** @return list<TicketMessage> */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * The customer-visible conversation (public replies only).
     *
     * @return list<TicketMessage>
     */
    public function publicMessages(): array
    {
        return array_values(array_filter($this->messages, static fn (TicketMessage $m): bool => $m->isPublic()));
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
