<?php

declare(strict_types=1);

namespace EruoFood\Support\Domain\Ticket;

use EruoFood\Support\Domain\Enum\TicketPriority;
use EruoFood\Support\Domain\Enum\TicketStatus;

/** A filter over the ticket queue. All fields optional. */
final readonly class TicketQuery
{
    public function __construct(
        public ?TicketStatus $status = null,
        public ?TicketPriority $priority = null,
        public ?string $assigneeId = null,
        public ?string $requesterId = null,
        public ?string $category = null,
        public bool $unassignedOnly = false,
        public bool $openOnly = false,
        public int $page = 1,
        public int $perPage = 20,
    ) {
    }
}
