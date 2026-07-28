<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Support;

use EruoFood\Shared\Domain\Paginated;

/** Persistence port for the {@see Ticket} aggregate. */
interface TicketRepository
{
    public function nextIdentity(): string;

    public function nextMessageIdentity(): string;

    public function findById(string $id): ?Ticket;

    /**
     * @return Paginated<Ticket>
     */
    public function search(?TicketStatus $status, ?string $assigneeId, int $page, int $perPage): Paginated;

    public function save(Ticket $ticket): void;
}
