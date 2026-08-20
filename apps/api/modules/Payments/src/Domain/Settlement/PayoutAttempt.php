<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Settlement;

use DateTimeImmutable;
use EruoFood\Payments\Domain\Enum\GatewayOutcome;
use EruoFood\Payments\Domain\Enum\PaymentProvider;
use EruoFood\Payments\Domain\Enum\PayoutAttemptState;
use EruoFood\Payments\Domain\Exception\PaymentsInvalidState;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * One attempt to move money to a merchant's bank account.
 *
 * ## Created before the provider is called
 *
 * {@see create()} produces a row in state `created`, and the service persists
 * it *before* it calls the gateway. If the process dies during the transfer,
 * the worst case is an attempt with no outcome — which the reconciler can find,
 * because it is looking for exactly that. Previously the worst case was money
 * gone and no row at all.
 *
 * ## Attempts are never retried, only superseded
 *
 * There is no method that resets an attempt. A retry is a new row with the next
 * `attempt_no` and a fresh idempotency key, so the question "how many times did
 * we try to pay this merchant, and what happened each time" has a permanent
 * answer.
 */
final class PayoutAttempt
{
    private function __construct(
        private readonly string $id,
        private readonly string $settlementRunId,
        private readonly int $attemptNo,
        private readonly PaymentProvider $provider,
        private ?string $providerReference,
        private readonly Money $amount,
        private PayoutAttemptState $state,
        private ?string $failureReason,
        private readonly string $idempotencyKey,
        private readonly string $correlationId,
        private ?string $rawResponseDigest,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $submittedAt,
        private ?DateTimeImmutable $settledAt,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    public static function create(
        string $id,
        string $settlementRunId,
        int $attemptNo,
        PaymentProvider $provider,
        Money $amount,
        string $idempotencyKey,
        string $correlationId,
        DateTimeImmutable $now,
    ): self {
        if ($amount->minorUnits <= 0) {
            throw new PaymentsInvalidState('A payout attempt must move a positive amount.');
        }
        if ($attemptNo < 1) {
            throw new PaymentsInvalidState('Payout attempts are numbered from 1.');
        }
        if (trim($idempotencyKey) === '') {
            // Without a key, a provider that received the instruction twice has
            // no way to recognise the second one.
            throw new PaymentsInvalidState('A payout attempt needs an idempotency key.');
        }

        return new self(
            $id,
            $settlementRunId,
            $attemptNo,
            $provider,
            null,
            $amount,
            PayoutAttemptState::Created,
            null,
            $idempotencyKey,
            $correlationId,
            null,
            $now,
            null,
            null,
            $now,
        );
    }

    /**
     * @param array{
     *   id: string, settlementRunId: string, attemptNo: int, provider: PaymentProvider,
     *   providerReference: string|null, amount: Money, state: PayoutAttemptState,
     *   failureReason: string|null, idempotencyKey: string, correlationId: string,
     *   rawResponseDigest: string|null, createdAt: DateTimeImmutable,
     *   submittedAt: DateTimeImmutable|null, settledAt: DateTimeImmutable|null,
     *   updatedAt: DateTimeImmutable
     * } $state
     */
    public static function reconstitute(array $state): self
    {
        return new self(
            $state['id'],
            $state['settlementRunId'],
            $state['attemptNo'],
            $state['provider'],
            $state['providerReference'],
            $state['amount'],
            $state['state'],
            $state['failureReason'],
            $state['idempotencyKey'],
            $state['correlationId'],
            $state['rawResponseDigest'],
            $state['createdAt'],
            $state['submittedAt'],
            $state['settledAt'],
            $state['updatedAt'],
        );
    }

    /**
     * Apply what the provider told us.
     *
     * One method rather than four, so that every outcome the gateway can report
     * is handled in one place and a new outcome cannot be quietly forgotten:
     * the `match` is exhaustive, so adding a case to {@see GatewayOutcome}
     * fails to compile until it is classified here.
     */
    public function applyOutcome(
        GatewayOutcome $outcome,
        ?string $providerReference,
        ?string $message,
        ?string $rawDigest,
        DateTimeImmutable $now,
    ): void {
        if ($providerReference !== null && trim($providerReference) !== '') {
            $this->providerReference = $providerReference;
        }
        $this->rawResponseDigest = $rawDigest;

        match ($outcome) {
            GatewayOutcome::Succeeded => $this->confirm($now),
            GatewayOutcome::Processing => $this->submit($now),
            GatewayOutcome::Failed => $this->reject($message ?? 'Provider declined the transfer.', $now),
            GatewayOutcome::Unknown => $this->markUnknown($message ?? 'No answer from the provider.', $now),
        };
    }

    public function submit(DateTimeImmutable $now): void
    {
        $this->transitionTo(PayoutAttemptState::Submitted, $now);
        $this->submittedAt ??= $now;
    }

    public function confirm(DateTimeImmutable $now): void
    {
        if ($this->providerReference === null) {
            // The database says the same thing. Both, because a confirmed
            // payout with no reference is an unverifiable claim that money left.
            throw new PaymentsInvalidState('A payout cannot be confirmed without a provider reference.');
        }

        if ($this->state === PayoutAttemptState::Created) {
            // The provider answered immediately. Pass through Submitted so the
            // timeline records that the instruction was sent.
            $this->submit($now);
        }

        $this->transitionTo(PayoutAttemptState::Confirmed, $now);
        $this->settledAt = $now;
    }

    public function reject(string $reason, DateTimeImmutable $now): void
    {
        $this->transitionTo(PayoutAttemptState::Rejected, $now);
        $this->failureReason = $this->safeReason($reason);
        $this->settledAt = $now;
    }

    public function markUnknown(string $reason, DateTimeImmutable $now): void
    {
        $this->transitionTo(PayoutAttemptState::Unknown, $now);
        $this->failureReason = $this->safeReason($reason);
        $this->submittedAt ??= $now;
    }

    public function beginReconciliation(DateTimeImmutable $now): void
    {
        $this->transitionTo(PayoutAttemptState::Reconciling, $now);
    }

    public function markReconciliationRequired(string $reason, DateTimeImmutable $now): void
    {
        $this->transitionTo(PayoutAttemptState::ReconciliationRequired, $now);
        $this->failureReason = $this->safeReason($reason);
    }

    private function transitionTo(PayoutAttemptState $next, DateTimeImmutable $now): void
    {
        if ($this->state === $next) {
            return; // Idempotent: a repeated identical answer is not an error.
        }

        if (! $this->state->canTransitionTo($next)) {
            throw new PaymentsInvalidState(sprintf(
                'Cannot move a payout attempt from "%s" to "%s".',
                $this->state->value,
                $next->value,
            ));
        }

        $this->state = $next;
        $this->updatedAt = $now;
    }

    /** Bounded and single-line: provider errors have been known to echo the request. */
    private function safeReason(string $reason): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', $reason) ?? ''), 0, 255);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function settlementRunId(): string
    {
        return $this->settlementRunId;
    }

    public function attemptNo(): int
    {
        return $this->attemptNo;
    }

    public function provider(): PaymentProvider
    {
        return $this->provider;
    }

    public function providerReference(): ?string
    {
        return $this->providerReference;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function state(): PayoutAttemptState
    {
        return $this->state;
    }

    public function failureReason(): ?string
    {
        return $this->failureReason;
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    public function rawResponseDigest(): ?string
    {
        return $this->rawResponseDigest;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function submittedAt(): ?DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function settledAt(): ?DateTimeImmutable
    {
        return $this->settledAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
