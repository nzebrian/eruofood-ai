<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Domain\Order;

use DateTimeImmutable;
use EruoFood\Commerce\Domain\Enum\OrderStatus;
use EruoFood\Commerce\Domain\Event\OrderPlaced;
use EruoFood\Commerce\Domain\Exception\CommerceInvalidState;
use EruoFood\Commerce\Domain\ValueObject\Address;
use EruoFood\Shared\Domain\AggregateRoot;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * A customer order — the aggregate root over its priced lines and its money
 * breakdown (subtotal → discount → tax → shipping → total). Prices and every
 * charge are captured at checkout, so later catalogue, tax or shipping changes
 * never rewrite a placed order. Status transitions are guarded by
 * {@see OrderStatus} and appended to an immutable history.
 */
final class Order extends AggregateRoot
{
    /**
     * @param list<OrderLine> $lines
     * @param list<array{status: string, at: string}> $statusHistory
     */
    private function __construct(
        private readonly string $id,
        private readonly string $reference,
        private readonly string $customerUserId,
        private array $lines,
        private readonly Money $subtotal,
        private readonly Money $discount,
        private readonly Money $tax,
        private readonly Money $shipping,
        private readonly Money $total,
        private readonly ?string $couponCode,
        private readonly bool $pickup,
        private readonly ?Address $shippingAddress,
        private readonly ?DateTimeImmutable $scheduledFor,
        private readonly ?string $note,
        private OrderStatus $status,
        private array $statusHistory,
        private readonly DateTimeImmutable $placedAt,
    ) {
    }

    /**
     * @param list<OrderLine> $lines
     */
    public static function place(
        string $id,
        string $reference,
        string $customerUserId,
        array $lines,
        Money $subtotal,
        Money $discount,
        Money $tax,
        Money $shipping,
        ?string $couponCode,
        bool $pickup,
        ?Address $shippingAddress,
        ?DateTimeImmutable $scheduledFor,
        ?string $note,
        DateTimeImmutable $now,
    ): self {
        if ($lines === []) {
            throw new CommerceInvalidState('Cannot place an order with no items.');
        }
        if (! $pickup && $shippingAddress === null) {
            throw new CommerceInvalidState('A shipped order requires a shipping address.');
        }

        $total = $subtotal->subtract($discount)->add($tax)->add($shipping);
        if ($total->minorUnits < 0) {
            throw new InvalidArgumentException('Order total cannot be negative.');
        }

        $order = new self(
            $id, $reference, $customerUserId, array_values($lines), $subtotal, $discount,
            $tax, $shipping, $total, $couponCode, $pickup, $shippingAddress, $scheduledFor,
            $note, OrderStatus::Pending,
            [['status' => OrderStatus::Pending->value, 'at' => $now->format(DATE_ATOM)]], $now,
        );
        $order->recordThat(new OrderPlaced($id, $customerUserId, $total->minorUnits));

        return $order;
    }

    /**
     * @param list<OrderLine> $lines
     * @param list<array{status: string, at: string}> $statusHistory
     */
    public static function reconstitute(
        string $id,
        string $reference,
        string $customerUserId,
        array $lines,
        Money $subtotal,
        Money $discount,
        Money $tax,
        Money $shipping,
        Money $total,
        ?string $couponCode,
        bool $pickup,
        ?Address $shippingAddress,
        ?DateTimeImmutable $scheduledFor,
        ?string $note,
        OrderStatus $status,
        array $statusHistory,
        DateTimeImmutable $placedAt,
    ): self {
        return new self(
            $id, $reference, $customerUserId, array_values($lines), $subtotal, $discount,
            $tax, $shipping, $total, $couponCode, $pickup, $shippingAddress, $scheduledFor,
            $note, $status, array_values($statusHistory), $placedAt,
        );
    }

    public function transitionTo(OrderStatus $next, DateTimeImmutable $at): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw new CommerceInvalidState(sprintf(
                'Cannot move an order from "%s" to "%s".',
                $this->status->value,
                $next->value,
            ));
        }
        $this->status = $next;
        $this->statusHistory[] = ['status' => $next->value, 'at' => $at->format(DATE_ATOM)];
    }

    public function cancel(DateTimeImmutable $at): void
    {
        $this->transitionTo(OrderStatus::Cancelled, $at);
    }

    public function markReturned(DateTimeImmutable $at): void
    {
        $this->transitionTo(OrderStatus::Returned, $at);
    }

    public function isForCustomer(string $userId): bool
    {
        return $this->customerUserId === $userId;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function reference(): string
    {
        return $this->reference;
    }

    public function customerUserId(): string
    {
        return $this->customerUserId;
    }

    /** @return list<OrderLine> */
    public function lines(): array
    {
        return $this->lines;
    }

    public function subtotal(): Money
    {
        return $this->subtotal;
    }

    public function discount(): Money
    {
        return $this->discount;
    }

    public function tax(): Money
    {
        return $this->tax;
    }

    public function shipping(): Money
    {
        return $this->shipping;
    }

    public function total(): Money
    {
        return $this->total;
    }

    public function couponCode(): ?string
    {
        return $this->couponCode;
    }

    public function isPickup(): bool
    {
        return $this->pickup;
    }

    public function shippingAddress(): ?Address
    {
        return $this->shippingAddress;
    }

    public function scheduledFor(): ?DateTimeImmutable
    {
        return $this->scheduledFor;
    }

    public function note(): ?string
    {
        return $this->note;
    }

    public function status(): OrderStatus
    {
        return $this->status;
    }

    /** @return list<array{status: string, at: string}> */
    public function statusHistory(): array
    {
        return $this->statusHistory;
    }

    public function placedAt(): DateTimeImmutable
    {
        return $this->placedAt;
    }
}
