<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Order;

use DateTimeImmutable;
use EruoFood\Commerce\Domain\Enum\ReturnStatus;
use EruoFood\Commerce\Domain\Exception\CommerceInvalidState;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * A customer's request to return an order and be refunded. Tracks its own
 * status lifecycle (requested → approved/rejected → refunded); the refund
 * amount is captured when the request is raised (defaults to the order total).
 */
final class ReturnRequest
{
    private function __construct(
        private readonly string $id,
        private readonly string $orderId,
        private readonly string $customerUserId,
        private readonly string $reason,
        private readonly Money $refundAmount,
        private ReturnStatus $status,
        private ?string $resolutionNote,
        private readonly DateTimeImmutable $requestedAt,
        private ?DateTimeImmutable $resolvedAt,
    ) {
    }

    public static function open(
        string $id,
        string $orderId,
        string $customerUserId,
        string $reason,
        Money $refundAmount,
        DateTimeImmutable $now,
    ): self {
        return new self(
            $id,
            $orderId,
            $customerUserId,
            $reason,
            $refundAmount,
            ReturnStatus::Requested,
            null,
            $now,
            null,
        );
    }

    public static function reconstitute(
        string $id,
        string $orderId,
        string $customerUserId,
        string $reason,
        Money $refundAmount,
        ReturnStatus $status,
        ?string $resolutionNote,
        DateTimeImmutable $requestedAt,
        ?DateTimeImmutable $resolvedAt,
    ): self {
        return new self(
            $id,
            $orderId,
            $customerUserId,
            $reason,
            $refundAmount,
            $status,
            $resolutionNote,
            $requestedAt,
            $resolvedAt,
        );
    }

    public function transitionTo(ReturnStatus $next, ?string $note, DateTimeImmutable $at): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw new CommerceInvalidState(sprintf(
                'Cannot move a return from "%s" to "%s".',
                $this->status->value,
                $next->value,
            ));
        }
        $this->status = $next;
        $this->resolutionNote = $note;
        $this->resolvedAt = $at;
    }

    public function isForCustomer(string $userId): bool
    {
        return $this->customerUserId === $userId;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function orderId(): string
    {
        return $this->orderId;
    }

    public function customerUserId(): string
    {
        return $this->customerUserId;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function refundAmount(): Money
    {
        return $this->refundAmount;
    }

    public function status(): ReturnStatus
    {
        return $this->status;
    }

    public function resolutionNote(): ?string
    {
        return $this->resolutionNote;
    }

    public function requestedAt(): DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function resolvedAt(): ?DateTimeImmutable
    {
        return $this->resolvedAt;
    }
}
