<?php

declare(strict_types=1);

namespace EruoFood\Support\Domain\Csat;

/** Persistence port for CSAT responses. */
interface CsatRepository
{
    public function nextIdentity(): string;

    public function findByTicket(string $ticketId): ?CsatResponse;

    public function save(CsatResponse $response): void;

    /** Aggregate satisfaction stats over the last N days. */
    public function summary(int $days): CsatSummary;
}
