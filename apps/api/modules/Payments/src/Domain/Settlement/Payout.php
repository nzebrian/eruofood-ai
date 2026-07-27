<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Settlement;

use DateTimeImmutable;
use EruoFood\Payments\Domain\Enum\PayoutStatus;
use EruoFood\Payments\Domain\Exception\PaymentsInvalidState;
use EruoFood\Payments\Domain\ValueObject\BankAccount;
use EruoFood\Shared\Domain\ValueObject\Money;

/** A transfer of funds to a payee's bank account, executed via a provider. */
final class Payout
{
    private function __construct(
        private readonly string $id,
        private readonly string $payeeType,
        private readonly string $payeeId,
        private readonly Money $amount,
        private readonly BankAccount $destination,
        private PayoutStatus $status,
        private ?string $providerReference,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $paidAt,
    ) {
    }

    public static function open(
        string $id,
        string $payeeType,
        string $payeeId,
        Money $amount,
        BankAccount $destination,
        DateTimeImmutable $now,
    ): self {
        if ($amount->minorUnits <= 0) {
            throw new PaymentsInvalidState('Payout amount must be positive.');
        }

        return new self($id, $payeeType, $payeeId, $amount, $destination, PayoutStatus::Pending, null, $now, null);
    }

    public static function reconstitute(
        string $id,
        string $payeeType,
        string $payeeId,
        Money $amount,
        BankAccount $destination,
        PayoutStatus $status,
        ?string $providerReference,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $paidAt,
    ): self {
        return new self($id, $payeeType, $payeeId, $amount, $destination, $status, $providerReference, $createdAt, $paidAt);
    }

    public function markProcessing(string $providerReference): void
    {
        $this->transition(PayoutStatus::Processing);
        $this->providerReference = $providerReference;
    }

    public function markPaid(DateTimeImmutable $at): void
    {
        $this->transition(PayoutStatus::Paid);
        $this->paidAt = $at;
    }

    public function fail(): void
    {
        $this->transition(PayoutStatus::Failed);
    }

    private function transition(PayoutStatus $next): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw new PaymentsInvalidState(sprintf('Cannot move a payout from "%s" to "%s".', $this->status->value, $next->value));
        }
        $this->status = $next;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function payeeType(): string
    {
        return $this->payeeType;
    }

    public function payeeId(): string
    {
        return $this->payeeId;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function destination(): BankAccount
    {
        return $this->destination;
    }

    public function status(): PayoutStatus
    {
        return $this->status;
    }

    public function providerReference(): ?string
    {
        return $this->providerReference;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function paidAt(): ?DateTimeImmutable
    {
        return $this->paidAt;
    }
}
