<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Support;

use DateTimeImmutable;

/**
 * A single message on a support ticket. An entity within the {@see Ticket}
 * aggregate. Internal notes ({@see isInternal()}) are visible only to staff
 * and never shown to the requester.
 */
final readonly class TicketMessage
{
    public function __construct(
        public string $id,
        public string $authorId,
        public string $body,
        public bool $internal,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
