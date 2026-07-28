<?php

declare(strict_types=1);

namespace EruoFood\Support\Domain\Ticket;

use EruoFood\Shared\Domain\Paginated;

/** Persistence port for the {@see Ticket} aggregate. */
interface TicketRepository
{
    public function nextIdentity(): string;

    public function nextMessageIdentity(): string;

    /** The next human-readable ticket reference (e.g. "EF-000123"). */
    public function nextReference(): string;

    public function findById(string $id): ?Ticket;

    public function findByRef(string $ref): ?Ticket;

    /**
     * @return Paginated<Ticket>
     */
    public function search(TicketQuery $query): Paginated;

    /**
     * Open, unresolved tickets whose resolution SLA has passed — the SLA
     * scanner's work list.
     *
     * @return list<Ticket>
     */
    public function breachingResolution(\DateTimeImmutable $now, int $limit): array;

    public function save(Ticket $ticket): void;
}
