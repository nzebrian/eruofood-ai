<?php

declare(strict_types=1);

namespace EruoFood\Support\Domain\Csat;

use DateTimeImmutable;

/** A customer-satisfaction rating (1–5) with an optional comment, per ticket. */
final readonly class CsatResponse
{
    public function __construct(
        public string $id,
        public string $ticketId,
        public string $userId,
        public int $score,
        public ?string $comment,
        public ?string $agentId,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
