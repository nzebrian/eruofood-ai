<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Settlement;

use DateTimeImmutable;
use EruoFood\Payments\Domain\Enum\DiscrepancyKind;
use EruoFood\Payments\Domain\Enum\ReconciliationState;
use EruoFood\Payments\Domain\Exception\PaymentsInvalidState;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * A disagreement between two things that should agree.
 *
 * ## Nothing here corrects anything
 *
 * A case records that two numbers differ, what they were, and what was decided.
 * It has no method that edits a ledger entry, an accrual, a run or an attempt.
 * The only closure that changes money is {@see resolveAdjusted()}, and it does
 * not perform the adjustment — it *records the id of a compensating posting
 * somebody else made*, and refuses to close without one.
 *
 * That separation is the whole design. A reconciler that could fix what it
 * found would eventually fix something it had misread, and the correction would
 * look exactly like the discrepancy never existing.
 */
final class ReconciliationCase
{
    private function __construct(
        private readonly string $id,
        private readonly DiscrepancyKind $kind,
        private readonly string $subjectType,
        private readonly string $subjectId,
        private readonly Money $expected,
        private readonly Money $observed,
        private ReconciliationState $state,
        private readonly ?string $detail,
        private readonly DateTimeImmutable $openedAt,
        private ?DateTimeImmutable $resolvedAt,
        private ?string $resolvedBy,
        private ?string $resolutionNote,
        private ?string $compensatingPostingId,
        private readonly string $correlationId,
        private int $version,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    public static function open(
        string $id,
        DiscrepancyKind $kind,
        string $subjectType,
        string $subjectId,
        Money $expected,
        Money $observed,
        ?string $detail,
        string $correlationId,
        DateTimeImmutable $now,
    ): self {
        if ($expected->currency !== $observed->currency) {
            throw new PaymentsInvalidState('A reconciliation case cannot compare two currencies.');
        }

        if (trim($subjectId) === '') {
            // A case with no subject cannot be deduplicated, and an
            // un-deduplicated case is a queue that fills with one problem. See
            // the migration for why this is not merely nullable-with-a-default.
            throw new PaymentsInvalidState('A reconciliation case needs a subject.');
        }

        return new self(
            $id,
            $kind,
            $subjectType,
            $subjectId,
            $expected,
            $observed,
            ReconciliationState::Open,
            $detail,
            $now,
            null,
            null,
            null,
            null,
            $correlationId,
            0,
            $now,
        );
    }

    /**
     * @param array{
     *   id: string, kind: DiscrepancyKind, subjectType: string, subjectId: string,
     *   expected: Money, observed: Money, state: ReconciliationState, detail: string|null,
     *   openedAt: DateTimeImmutable, resolvedAt: DateTimeImmutable|null,
     *   resolvedBy: string|null, resolutionNote: string|null,
     *   compensatingPostingId: string|null, correlationId: string, version: int,
     *   updatedAt: DateTimeImmutable
     * } $state
     */
    public static function reconstitute(array $state): self
    {
        return new self(
            $state['id'],
            $state['kind'],
            $state['subjectType'],
            $state['subjectId'],
            $state['expected'],
            $state['observed'],
            $state['state'],
            $state['detail'],
            $state['openedAt'],
            $state['resolvedAt'],
            $state['resolvedBy'],
            $state['resolutionNote'],
            $state['compensatingPostingId'],
            $state['correlationId'],
            $state['version'],
            $state['updatedAt'],
        );
    }

    public function beginInvestigation(DateTimeImmutable $now): void
    {
        $this->transitionTo(ReconciliationState::Investigating, $now);
    }

    /**
     * The two sides turned out to agree after all.
     *
     * The only resolution the system may reach unattended, and only for a kind
     * that {@see DiscrepancyKind::isAutoResolvable()} permits — which is the
     * provider mismatch alone. A drift between two numbers the platform itself
     * wrote cannot resolve itself by being asked twice.
     *
     * $resolvedBy is null when a reconciler closed it and a user id when a
     * person did.
     */
    public function resolveMatched(?string $resolvedBy, string $note, DateTimeImmutable $now): void
    {
        if ($resolvedBy === null && ! $this->kind->isAutoResolvable()) {
            throw new PaymentsInvalidState(sprintf(
                'A %s discrepancy cannot be closed automatically; it needs a named resolver.',
                $this->kind->value,
            ));
        }

        $this->transitionTo(ReconciliationState::ResolvedMatched, $now);
        $this->resolvedBy = $resolvedBy;
        $this->resolutionNote = $this->trim($note);
        $this->resolvedAt = $now;
    }

    /**
     * Somebody decided the books were wrong and posted a correction.
     *
     * Both arguments are required and neither is defaulted. The database
     * enforces the same pair with a CHECK, for the paths that never come
     * through this method.
     */
    public function resolveAdjusted(
        string $resolvedBy,
        string $compensatingPostingId,
        string $note,
        DateTimeImmutable $now,
    ): void {
        if (trim($resolvedBy) === '') {
            throw new PaymentsInvalidState('An adjusted resolution needs a named approver.');
        }
        if (trim($compensatingPostingId) === '') {
            throw new PaymentsInvalidState(
                'An adjusted resolution needs the id of the compensating ledger posting. '
                .'A case cannot be closed by writing a note.',
            );
        }
        if (trim($note) === '') {
            throw new PaymentsInvalidState('An adjusted resolution needs a reason.');
        }

        $this->transitionTo(ReconciliationState::ResolvedAdjusted, $now);
        $this->resolvedBy = $resolvedBy;
        $this->compensatingPostingId = $compensatingPostingId;
        $this->resolutionNote = $this->trim($note);
        $this->resolvedAt = $now;
    }

    public function escalate(string $note, DateTimeImmutable $now): void
    {
        $this->transitionTo(ReconciliationState::Escalated, $now);
        $this->resolutionNote = $this->trim($note);
    }

    private function transitionTo(ReconciliationState $next, DateTimeImmutable $now): void
    {
        if (! $this->state->canTransitionTo($next)) {
            throw new PaymentsInvalidState(sprintf(
                'Cannot move a reconciliation case from "%s" to "%s".',
                $this->state->value,
                $next->value,
            ));
        }

        $this->state = $next;
        $this->version++;
        $this->updatedAt = $now;
    }

    private function trim(string $value): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', $value) ?? ''), 0, 500);
    }

    /** How far apart the two sides are. Signed: positive means we expected more than we saw. */
    public function differenceMinor(): int
    {
        return $this->expected->minorUnits - $this->observed->minorUnits;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function kind(): DiscrepancyKind
    {
        return $this->kind;
    }

    public function subjectType(): string
    {
        return $this->subjectType;
    }

    public function subjectId(): string
    {
        return $this->subjectId;
    }

    public function expected(): Money
    {
        return $this->expected;
    }

    public function observed(): Money
    {
        return $this->observed;
    }

    public function state(): ReconciliationState
    {
        return $this->state;
    }

    public function detail(): ?string
    {
        return $this->detail;
    }

    public function openedAt(): DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function resolvedAt(): ?DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function resolvedBy(): ?string
    {
        return $this->resolvedBy;
    }

    public function resolutionNote(): ?string
    {
        return $this->resolutionNote;
    }

    public function compensatingPostingId(): ?string
    {
        return $this->compensatingPostingId;
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
