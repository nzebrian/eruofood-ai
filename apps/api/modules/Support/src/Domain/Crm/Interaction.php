<?php

declare(strict_types=1);

namespace EruoFood\Support\Domain\Crm;

use DateTimeImmutable;

/**
 * A single entry on the unified customer timeline — a registration, an order, a
 * payment, a ticket event. Append-only; assembled from domain events so the
 * agent sees the whole relationship in one place without querying other contexts.
 */
final readonly class Interaction
{
    public function __construct(
        public string $id,
        public string $userId,
        public string $kind,
        public string $summary,
        public ?string $ref,
        public string $source,
        public DateTimeImmutable $occurredAt,
    ) {
    }
}
