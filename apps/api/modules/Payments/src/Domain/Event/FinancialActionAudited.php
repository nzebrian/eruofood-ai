<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Event;

use DateTimeImmutable;
use EruoFood\Shared\Domain\DomainEvent;

/**
 * A privileged financial action happened, and here is everything needed to
 * judge it later.
 *
 * ## Every field is here because a question needs it
 *
 * - `actorId` — who. Null only for a scheduled reconciler, which is itself the
 *   answer to "who": nobody, the system.
 * - `auditAction` — what, specifically. `finance.settlement_executed` rather
 *   than a generic "payments event", so the compliance trail is filterable.
 * - `subjectType` / `subjectId` — which thing.
 * - `amountMinor` / `currency` — how much. An audit entry for a money movement
 *   that does not say how much money is not an audit entry.
 * - `reason` — why. Required for anything discretionary.
 * - `correlationId` — which request. Server-generated, never the caller's
 *   header.
 * - `idempotencyKey` — which attempt, so a duplicate submission and a genuine
 *   second attempt are distinguishable a year later.
 * - `beforeState` / `afterState` — what changed.
 * - `result` — whether it worked. Failures are audited too: a refused
 *   settlement is exactly what a review is looking for.
 *
 * ## Why an event rather than a direct write
 *
 * Admin owns the audit log. Payments writing into `admin_audit_entries` would
 * break the module boundary the rest of this codebase keeps, so it publishes
 * and Admin's translator subscribes by event name — the same one-way coupling
 * M24 uses for regulated-data access.
 *
 * ## What is deliberately absent
 *
 * No bank account, no provider payload, no credential, no raw provider message.
 * The audit trail records that money moved and on whose authority; it is not a
 * copy of the transfer instruction.
 */
final readonly class FinancialActionAudited implements DomainEvent
{
    private DateTimeImmutable $occurredAt;

    public function __construct(
        public ?string $actorId,
        public string $auditAction,
        public string $subjectType,
        public string $subjectId,
        public ?int $amountMinor,
        public ?string $currency,
        public ?string $reason,
        public string $correlationId,
        public ?string $idempotencyKey,
        public ?string $beforeState,
        public ?string $afterState,
        public string $result,
    ) {
        $this->occurredAt = new DateTimeImmutable();
    }

    public function eventName(): string
    {
        return 'payments.financial_action';
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
