<?php

declare(strict_types=1);

namespace EruoFood\Support\Domain\Ticket;

use DateTimeImmutable;
use EruoFood\Support\Domain\Enum\MessageAuthorType;
use EruoFood\Support\Domain\ValueObject\Attachment;

/**
 * A single message on a ticket — a customer message, an agent public reply, an
 * internal note, a system/automation note, or a bot reply. An entity within the
 * {@see Ticket} aggregate. Internal notes are never shown to the customer.
 */
final readonly class TicketMessage
{
    /**
     * @param list<Attachment> $attachments
     */
    public function __construct(
        public string $id,
        public MessageAuthorType $authorType,
        public ?string $authorId,
        public string $body,
        public bool $internal,
        public array $attachments,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /** Visible to the customer (public, non-internal, not a staff-only note). */
    public function isPublic(): bool
    {
        return ! $this->internal && $this->authorType !== MessageAuthorType::System;
    }

    public function isFromAgent(): bool
    {
        return $this->authorType === MessageAuthorType::Agent;
    }
}
