<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Domain\Order;

use DateTimeImmutable;
use EruoFood\Marketplace\Domain\Enum\FulfilmentType;
use EruoFood\Marketplace\Domain\Enum\OrderStatus;
use EruoFood\Marketplace\Domain\Event\OrderPlaced;
use EruoFood\Marketplace\Domain\Exception\MarketplaceInvalidState;
use EruoFood\Marketplace\Domain\ValueObject\Address;
use EruoFood\Shared\Domain\AggregateRoot;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * A customer order — the aggregate root over its priced lines, totals, fulfilment
 * and status timeline. Prices are captured at checkout, so later menu changes
 * never alter a placed order. Status transitions are guarded by {@see OrderStatus}
 * and appended to an immutable history.
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
        private readonly string $vendorId,
        private array $lines,
        private readonly Money $subtotal,
        private readonly Money $deliveryFee,
        private readonly Money $total,
        private readonly FulfilmentType $fulfilment,
        private readonly ?Address $deliveryAddress,
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
        string $vendorId,
        array $lines,
        Money $deliveryFee,
        FulfilmentType $fulfilment,
        ?Address $deliveryAddress,
        ?DateTimeImmutable $scheduledFor,
        ?string $note,
        DateTimeImmutable $now,
    ): self {
        if ($lines === []) {
            throw new MarketplaceInvalidState('Cannot place an order with no items.');
        }
        if ($fulfilment === FulfilmentType::Delivery && $deliveryAddress === null) {
            throw new MarketplaceInvalidState('A delivery order requires a delivery address.');
        }

        $subtotal = self::sum($lines, $deliveryFee->currency);
        $total = $subtotal->add($deliveryFee);

        $order = new self(
            $id, $reference, $customerUserId, $vendorId, array_values($lines),
            $subtotal, $deliveryFee, $total, $fulfilment, $deliveryAddress,
            $scheduledFor, $note, OrderStatus::Pending,
            [['status' => OrderStatus::Pending->value, 'at' => $now->format(DATE_ATOM)]], $now,
        );
        $order->recordThat(new OrderPlaced($id, $vendorId, $customerUserId, $total->minorUnits));

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
        string $vendorId,
        array $lines,
        Money $subtotal,
        Money $deliveryFee,
        Money $total,
        FulfilmentType $fulfilment,
        ?Address $deliveryAddress,
        ?DateTimeImmutable $scheduledFor,
        ?string $note,
        OrderStatus $status,
        array $statusHistory,
        DateTimeImmutable $placedAt,
    ): self {
        return new self(
            $id, $reference, $customerUserId, $vendorId, array_values($lines),
            $subtotal, $deliveryFee, $total, $fulfilment, $deliveryAddress,
            $scheduledFor, $note, $status, array_values($statusHistory), $placedAt,
        );
    }

    /** Move the order to a new status, enforcing valid transitions. */
    public function transitionTo(OrderStatus $next, DateTimeImmutable $at): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw new MarketplaceInvalidState(sprintf(
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

    public function isForCustomer(string $userId): bool
    {
        return $this->customerUserId === $userId;
    }

    /**
     * @param list<OrderLine> $lines
     */
    private static function sum(array $lines, string $currency): Money
    {
        $total = new Money(0, $currency);
        foreach ($lines as $line) {
            if ($line->unitPrice->currency !== $currency) {
                throw new InvalidArgumentException('All order lines must share a currency.');
            }
            $total = $total->add($line->lineTotal());
        }

        return $total;
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

    public function vendorId(): string
    {
        return $this->vendorId;
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

    public function deliveryFee(): Money
    {
        return $this->deliveryFee;
    }

    public function total(): Money
    {
        return $this->total;
    }

    public function fulfilment(): FulfilmentType
    {
        return $this->fulfilment;
    }

    public function deliveryAddress(): ?Address
    {
        return $this->deliveryAddress;
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
