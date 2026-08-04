<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Payment;

use DateTimeImmutable;
use EruoFood\Payments\Domain\Enum\PaymentMethodType;
use EruoFood\Payments\Domain\Enum\PaymentProvider;
use EruoFood\Payments\Domain\Enum\PaymentStatus;
use EruoFood\Payments\Domain\Event\PaymentFailed;
use EruoFood\Payments\Domain\Event\PaymentSucceeded;
use EruoFood\Payments\Domain\Exception\PaymentsInvalidState;
use EruoFood\Payments\Domain\ValueObject\PaymentSplit;
use EruoFood\Payments\Domain\ValueObject\ProviderReference;
use EruoFood\Shared\Domain\AggregateRoot;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * A payment — the aggregate root over an attempt to move money from a payer to
 * the platform (and, via splits, on to payees). It is deliberately decoupled
 * from the Order module: it holds an **opaque** order reference (a soft id) and
 * never imports order code. Its lifecycle is guarded by {@see PaymentStatus},
 * it is idempotent on an idempotency key, and it records domain events that
 * other contexts may subscribe to.
 *
 * @phpstan-type SplitList list<PaymentSplit>
 */
final class Payment extends AggregateRoot
{
    /**
     * @param list<PaymentSplit> $splits
     * @param list<array{status: string, at: string, note: string|null}> $timeline
     */
    private function __construct(
        private readonly string $id,
        private readonly string $reference,
        private readonly ?string $orderId,
        private readonly string $payerUserId,
        private readonly Money $amount,
        private Money $refundedAmount,
        private PaymentStatus $status,
        private readonly PaymentProvider $provider,
        private readonly PaymentMethodType $methodType,
        private ?ProviderReference $providerReference,
        private readonly string $idempotencyKey,
        private array $splits,
        private ?string $failureReason,
        private array $timeline,
        private readonly DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param list<PaymentSplit> $splits
     */
    public static function initiate(
        string $id,
        string $reference,
        ?string $orderId,
        string $payerUserId,
        Money $amount,
        PaymentProvider $provider,
        PaymentMethodType $methodType,
        string $idempotencyKey,
        array $splits,
        DateTimeImmutable $now,
    ): self {
        if ($amount->minorUnits <= 0) {
            throw new InvalidArgumentException('Payment amount must be positive.');
        }
        self::assertSplitsWithin($splits, $amount);

        return new self(
            $id,
            $reference,
            $orderId,
            $payerUserId,
            $amount,
            new Money(0, $amount->currency),
            PaymentStatus::Pending,
            $provider,
            $methodType,
            null,
            $idempotencyKey,
            $splits,
            null,
            [['status' => PaymentStatus::Pending->value, 'at' => $now->format(DATE_ATOM), 'note' => null]],
            $now,
        );
    }

    /**
     * @param list<PaymentSplit> $splits
     * @param list<array{status: string, at: string, note: string|null}> $timeline
     */
    public static function reconstitute(
        string $id,
        string $reference,
        ?string $orderId,
        string $payerUserId,
        Money $amount,
        Money $refundedAmount,
        PaymentStatus $status,
        PaymentProvider $provider,
        PaymentMethodType $methodType,
        ?ProviderReference $providerReference,
        string $idempotencyKey,
        array $splits,
        ?string $failureReason,
        array $timeline,
        DateTimeImmutable $createdAt,
    ): self {
        return new self(
            $id,
            $reference,
            $orderId,
            $payerUserId,
            $amount,
            $refundedAmount,
            $status,
            $provider,
            $methodType,
            $providerReference,
            $idempotencyKey,
            $splits,
            $failureReason,
            $timeline,
            $createdAt,
        );
    }

    /** Record that the provider accepted the initialization (awaiting confirmation). */
    public function markProcessing(ProviderReference $reference, DateTimeImmutable $at): void
    {
        $this->transition(PaymentStatus::Processing, $at, null);
        $this->providerReference = $reference;
    }

    public function markSucceeded(DateTimeImmutable $at, ?ProviderReference $reference = null): void
    {
        if ($this->status === PaymentStatus::Succeeded) {
            return; // idempotent — already captured
        }
        $this->transition(PaymentStatus::Succeeded, $at, null);
        if ($reference !== null) {
            $this->providerReference = $reference;
        }
        $this->recordThat(new PaymentSucceeded(
            $this->id,
            $this->orderId,
            $this->payerUserId,
            $this->amount->minorUnits,
            $this->amount->currency,
            $this->provider->value,
        ));
    }

    public function markFailed(string $reason, DateTimeImmutable $at): void
    {
        if ($this->status->isTerminal()) {
            return;
        }
        $this->transition(PaymentStatus::Failed, $at, $reason);
        $this->failureReason = $reason;
        $this->recordThat(new PaymentFailed($this->id, $this->orderId, $this->payerUserId, $reason));
    }

    public function cancel(DateTimeImmutable $at): void
    {
        $this->transition(PaymentStatus::Cancelled, $at, null);
    }

    /** Apply a (partial) refund, adjusting status. Returns whether it is now fully refunded. */
    public function applyRefund(Money $amount, DateTimeImmutable $at): bool
    {
        if (! $this->status->isCaptured()) {
            throw new PaymentsInvalidState('Only a captured payment can be refunded.');
        }
        $newRefunded = $this->refundedAmount->add($amount);
        if ($newRefunded->minorUnits > $this->amount->minorUnits) {
            throw new PaymentsInvalidState('Refund exceeds the captured amount.');
        }
        $this->refundedAmount = $newRefunded;
        $fully = $newRefunded->minorUnits === $this->amount->minorUnits;
        $this->transition($fully ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded, $at, null);

        return $fully;
    }

    public function refundableAmount(): Money
    {
        return $this->amount->subtract($this->refundedAmount);
    }

    public function isForPayer(string $userId): bool
    {
        return $this->payerUserId === $userId;
    }

    private function transition(PaymentStatus $next, DateTimeImmutable $at, ?string $note): void
    {
        if ($this->status !== $next && ! $this->status->canTransitionTo($next)) {
            throw new PaymentsInvalidState(sprintf(
                'Cannot move a payment from "%s" to "%s".',
                $this->status->value,
                $next->value,
            ));
        }
        $this->status = $next;
        $this->timeline[] = ['status' => $next->value, 'at' => $at->format(DATE_ATOM), 'note' => $note];
    }

    /**
     * @param list<PaymentSplit> $splits
     */
    private static function assertSplitsWithin(array $splits, Money $amount): void
    {
        $sum = 0;
        foreach ($splits as $split) {
            if ($split->amount->currency !== $amount->currency) {
                throw new InvalidArgumentException('Split currency mismatch.');
            }
            $sum += $split->amount->minorUnits;
        }
        if ($sum > $amount->minorUnits) {
            throw new PaymentsInvalidState('Splits exceed the payment amount.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function reference(): string
    {
        return $this->reference;
    }

    public function orderId(): ?string
    {
        return $this->orderId;
    }

    public function payerUserId(): string
    {
        return $this->payerUserId;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function refundedAmount(): Money
    {
        return $this->refundedAmount;
    }

    public function status(): PaymentStatus
    {
        return $this->status;
    }

    public function provider(): PaymentProvider
    {
        return $this->provider;
    }

    public function methodType(): PaymentMethodType
    {
        return $this->methodType;
    }

    public function providerReference(): ?ProviderReference
    {
        return $this->providerReference;
    }

    public function idempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    /** @return list<PaymentSplit> */
    public function splits(): array
    {
        return $this->splits;
    }

    public function failureReason(): ?string
    {
        return $this->failureReason;
    }

    /** @return list<array{status: string, at: string, note: string|null}> */
    public function timeline(): array
    {
        return $this->timeline;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
