<?php

declare(strict_types=1);

namespace EruoFood\Payments\Domain\Payment;

use DateTimeImmutable;
use EruoFood\Payments\Domain\Enum\RefundStatus;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * A refund against a captured payment — full or partial. Tracks its own status
 * so a provider callback can complete or fail it; the parent Payment's refunded
 * total is adjusted by the application service when a refund completes.
 */
final class Refund
{
    private function __construct(
        private readonly string $id,
        private readonly string $paymentId,
        private readonly ?string $orderId,
        private readonly Money $amount,
        private readonly bool $partial,
        private readonly string $reason,
        private RefundStatus $status,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $completedAt,
    ) {
    }

    public static function open(
        string $id,
        string $paymentId,
        ?string $orderId,
        Money $amount,
        bool $partial,
        string $reason,
        DateTimeImmutable $now,
    ): self {
        return new self($id, $paymentId, $orderId, $amount, $partial, $reason, RefundStatus::Pending, $now, null);
    }

    public static function reconstitute(
        string $id,
        string $paymentId,
        ?string $orderId,
        Money $amount,
        bool $partial,
        string $reason,
        RefundStatus $status,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $completedAt,
    ): self {
        return new self($id, $paymentId, $orderId, $amount, $partial, $reason, $status, $createdAt, $completedAt);
    }

    public function complete(DateTimeImmutable $at): void
    {
        $this->status = RefundStatus::Completed;
        $this->completedAt = $at;
    }

    public function fail(DateTimeImmutable $at): void
    {
        $this->status = RefundStatus::Failed;
        $this->completedAt = $at;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function paymentId(): string
    {
        return $this->paymentId;
    }

    public function orderId(): ?string
    {
        return $this->orderId;
    }

    public function amount(): Money
    {
        return $this->amount;
    }

    public function isPartial(): bool
    {
        return $this->partial;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function status(): RefundStatus
    {
        return $this->status;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function completedAt(): ?DateTimeImmutable
    {
        return $this->completedAt;
    }
}
