<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Settlement;

use DateTimeImmutable;
use EruoFood\Payments\Domain\Enum\SettlementRunState;
use EruoFood\Payments\Domain\Exception\PaymentsInvalidState;
use EruoFood\Payments\Domain\Exception\PaymentsNotAuthorized;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * One settlement of one merchant over one window.
 *
 * ## Computed, then approved, then executed — by two different people
 *
 * The aggregate exists in {@see SettlementRunState::Draft} before anyone can
 * act on it, holding a total that was derived from accruals rather than
 * supplied. Approval is a separate call by a principal holding `finance.settle`;
 * execution is a third call by a principal holding `finance.payout`.
 *
 * {@see approve()} and {@see beginExecution()} refuse the same actor for both.
 * That is separation of duties expressed where it can actually be enforced —
 * a permission model can say two *powers* are distinct, but only the aggregate
 * knows whether the same person used both. The database carries the same rule
 * as a CHECK, for the paths that never come through here.
 *
 * ## The transitions that are missing on purpose
 *
 * There is no `retry()` from {@see SettlementRunState::Unknown}, and no method
 * anywhere that moves a run out of `Unknown` except {@see beginReconciliation()}.
 * An unknown transfer may already have paid the merchant; the only safe next
 * step is to find out.
 *
 * ## Versioning
 *
 * `version` increments on every mutation and is checked by the repository on
 * write. It is the third of four concurrency layers — row lock, in-lock
 * re-read, optimistic version, unique index — and the only one that catches a
 * caller holding a stale copy of the aggregate.
 */
final class SettlementRun
{
    private function __construct(
        private readonly string $id,
        private readonly string $merchantType,
        private readonly string $merchantId,
        private readonly string $currency,
        private readonly DateTimeImmutable $windowStart,
        private readonly DateTimeImmutable $windowEnd,
        private Money $gross,
        private Money $commission,
        private Money $fee,
        private Money $net,
        private SettlementRunState $state,
        private readonly ?string $idempotencyKey,
        private readonly string $settlementReference,
        private readonly string $correlationId,
        private readonly ?string $computedBy,
        private readonly DateTimeImmutable $computedAt,
        private ?string $approvedBy,
        private ?DateTimeImmutable $approvedAt,
        private ?string $executedBy,
        private ?DateTimeImmutable $executedAt,
        private ?string $failureReason,
        private int $version,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * Open a draft from figures the caller derived from accruals.
     *
     * The totals are arguments rather than a single `net`, so the run records
     * what was deducted and not merely what was paid — a merchant asking "why
     * is this less than my sales" is answerable from the row.
     */
    public static function draft(
        string $id,
        string $merchantType,
        string $merchantId,
        string $currency,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
        Money $gross,
        Money $commission,
        Money $fee,
        ?string $idempotencyKey,
        string $settlementReference,
        string $correlationId,
        ?string $computedBy,
        DateTimeImmutable $now,
    ): self {
        if ($windowEnd <= $windowStart) {
            throw new PaymentsInvalidState('A settlement window must end after it starts.');
        }

        foreach ([$gross, $commission, $fee] as $amount) {
            if ($amount->currency !== $currency) {
                throw new PaymentsInvalidState('A settlement run cannot mix currencies.');
            }
            if ($amount->minorUnits < 0) {
                throw new PaymentsInvalidState('A settlement run cannot carry a negative amount.');
            }
        }

        $net = $gross->subtract($commission)->subtract($fee);
        if ($net->minorUnits <= 0) {
            // A zero run would create a payout for nothing and an idempotency
            // key that blocks the real one. Refused rather than silently
            // skipped so the caller has to decide what to do about it.
            throw new PaymentsInvalidState('A settlement run must pay out a positive amount.');
        }

        return new self(
            $id,
            $merchantType,
            $merchantId,
            $currency,
            $windowStart,
            $windowEnd,
            $gross,
            $commission,
            $fee,
            $net,
            SettlementRunState::Draft,
            $idempotencyKey,
            $settlementReference,
            $correlationId,
            $computedBy,
            $now,
            null,
            null,
            null,
            null,
            null,
            0,
            $now,
            $now,
        );
    }

    /**
     * @param array{
     *   id: string, merchantType: string, merchantId: string, currency: string,
     *   windowStart: DateTimeImmutable, windowEnd: DateTimeImmutable,
     *   gross: Money, commission: Money, fee: Money, net: Money,
     *   state: SettlementRunState, idempotencyKey: string|null,
     *   settlementReference: string, correlationId: string,
     *   computedBy: string|null, computedAt: DateTimeImmutable,
     *   approvedBy: string|null, approvedAt: DateTimeImmutable|null,
     *   executedBy: string|null, executedAt: DateTimeImmutable|null,
     *   failureReason: string|null, version: int,
     *   createdAt: DateTimeImmutable, updatedAt: DateTimeImmutable
     * } $state
     */
    public static function reconstitute(array $state): self
    {
        return new self(
            $state['id'],
            $state['merchantType'],
            $state['merchantId'],
            $state['currency'],
            $state['windowStart'],
            $state['windowEnd'],
            $state['gross'],
            $state['commission'],
            $state['fee'],
            $state['net'],
            $state['state'],
            $state['idempotencyKey'],
            $state['settlementReference'],
            $state['correlationId'],
            $state['computedBy'],
            $state['computedAt'],
            $state['approvedBy'],
            $state['approvedAt'],
            $state['executedBy'],
            $state['executedAt'],
            $state['failureReason'],
            $state['version'],
            $state['createdAt'],
            $state['updatedAt'],
        );
    }

    /** A named person decided this run is correct. */
    public function approve(string $actorId, DateTimeImmutable $now): void
    {
        if (trim($actorId) === '') {
            throw new PaymentsInvalidState('A settlement run needs a named approver.');
        }

        $this->transitionTo(SettlementRunState::Pending, $now);
        $this->approvedBy = $actorId;
        $this->approvedAt = $now;
    }

    /**
     * A *different* named person is starting the transfer.
     *
     * The four-eyes check lives here rather than in the service because it is a
     * rule about the aggregate's own history: only the run knows who approved
     * it. A service could be given the check and later refactored past it.
     */
    public function beginExecution(string $actorId, DateTimeImmutable $now): void
    {
        if (trim($actorId) === '') {
            throw new PaymentsInvalidState('A settlement run needs a named executor.');
        }

        if ($this->approvedBy !== null && $this->approvedBy === $actorId) {
            throw new PaymentsNotAuthorized(
                'The person who approved a settlement cannot also execute it.',
            );
        }

        $this->transitionTo(SettlementRunState::Processing, $now);
        $this->executedBy = $actorId;
        $this->executedAt = $now;
    }

    public function markSucceeded(DateTimeImmutable $now): void
    {
        $this->transitionTo(SettlementRunState::Succeeded, $now);
    }

    public function markFailed(string $reason, DateTimeImmutable $now): void
    {
        $this->transitionTo(SettlementRunState::Failed, $now);
        $this->failureReason = $this->safeReason($reason);
    }

    /**
     * The transfer was dispatched and we never heard back.
     *
     * Note what this does *not* do: it does not clear `executedBy`, does not
     * release the run's lines, and does not set a failure reason. The money may
     * be gone; nothing about this run may be reused until somebody establishes
     * whether it is.
     */
    public function markUnknown(string $reason, DateTimeImmutable $now): void
    {
        $this->transitionTo(SettlementRunState::Unknown, $now);
        $this->failureReason = $this->safeReason($reason);
    }

    public function beginReconciliation(DateTimeImmutable $now): void
    {
        $this->transitionTo(SettlementRunState::Reconciling, $now);
    }

    public function markReconciliationRequired(string $reason, DateTimeImmutable $now): void
    {
        $this->transitionTo(SettlementRunState::ReconciliationRequired, $now);
        $this->failureReason = $this->safeReason($reason);
    }

    /** A failed run may be attempted again — and only a failed one. */
    public function reopenForRetry(string $actorId, DateTimeImmutable $now): void
    {
        if ($this->state !== SettlementRunState::Failed) {
            throw new PaymentsInvalidState(sprintf(
                'Only a failed settlement run can be retried; this one is "%s".',
                $this->state->value,
            ));
        }

        $this->transitionTo(SettlementRunState::Pending, $now);
        // The retry is a fresh decision and needs a fresh approval, so the
        // executor slot is cleared and the four-eyes rule applies again.
        $this->approvedBy = $actorId;
        $this->approvedAt = $now;
        $this->executedBy = null;
        $this->executedAt = null;
        $this->failureReason = null;
    }

    public function cancel(DateTimeImmutable $now): void
    {
        $this->transitionTo(SettlementRunState::Cancelled, $now);
    }

    public function reverse(DateTimeImmutable $now): void
    {
        $this->transitionTo(SettlementRunState::Reversed, $now);
    }

    private function transitionTo(SettlementRunState $next, DateTimeImmutable $now): void
    {
        if (! $this->state->canTransitionTo($next)) {
            throw new PaymentsInvalidState(sprintf(
                'Cannot move a settlement run from "%s" to "%s".',
                $this->state->value,
                $next->value,
            ));
        }

        $this->state = $next;
        $this->version++;
        $this->updatedAt = $now;
    }

    /**
     * Truncate and strip a failure reason before it is stored.
     *
     * Provider error text has been known to echo the request back, which for a
     * transfer means an account number. Reasons are stored for operators, so
     * they are bounded and stripped of newlines that would break a log line.
     */
    private function safeReason(string $reason): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', $reason) ?? '');

        return mb_substr($clean, 0, 255);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function merchantType(): string
    {
        return $this->merchantType;
    }

    public function merchantId(): string
    {
        return $this->merchantId;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function windowStart(): DateTimeImmutable
    {
        return $this->windowStart;
    }

    public function windowEnd(): DateTimeImmutable
    {
        return $this->windowEnd;
    }

    public function gross(): Money
    {
        return $this->gross;
    }

    public function commission(): Money
    {
        return $this->commission;
    }

    public function fee(): Money
    {
        return $this->fee;
    }

    public function net(): Money
    {
        return $this->net;
    }

    public function state(): SettlementRunState
    {
        return $this->state;
    }

    public function idempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    public function settlementReference(): string
    {
        return $this->settlementReference;
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    public function computedBy(): ?string
    {
        return $this->computedBy;
    }

    public function computedAt(): DateTimeImmutable
    {
        return $this->computedAt;
    }

    public function approvedBy(): ?string
    {
        return $this->approvedBy;
    }

    public function approvedAt(): ?DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function executedBy(): ?string
    {
        return $this->executedBy;
    }

    public function executedAt(): ?DateTimeImmutable
    {
        return $this->executedAt;
    }

    public function failureReason(): ?string
    {
        return $this->failureReason;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
