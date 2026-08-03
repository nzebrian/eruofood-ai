<?php

declare(strict_types=1);

use EruoFood\Marketplace\Domain\Enum\FulfilmentType;
use EruoFood\Marketplace\Domain\Enum\OrderStatus;
use EruoFood\Marketplace\Domain\Exception\MarketplaceInvalidState;
use EruoFood\Marketplace\Domain\Order\Order;
use EruoFood\Marketplace\Domain\Order\OrderLine;
use EruoFood\Marketplace\Domain\ValueObject\Address;
use EruoFood\Marketplace\Domain\ValueObject\GeoLocation;
use EruoFood\Shared\Domain\ValueObject\Money;

function ngn(int $minor): Money
{
    return new Money($minor, 'NGN');
}

function sampleAddress(): Address
{
    return new Address('1 Demo St', 'Lagos', 'Lagos', new GeoLocation(6.5, 3.3));
}

/** @return list<OrderLine> */
function sampleLines(): array
{
    return [
        new OrderLine('i1', 'Jollof Rice', null, ngn(250000), 2),
        new OrderLine('i2', 'Suya', 'Large', ngn(350000), 1),
    ];
}

function placeOrder(FulfilmentType $f = FulfilmentType::Delivery): Order
{
    return Order::place(
        'o1',
        'EF-ABC123',
        'u1',
        'v1',
        sampleLines(),
        ngn(50000),
        $f,
        $f === FulfilmentType::Delivery ? sampleAddress() : null,
        null,
        null,
        new DateTimeImmutable('2026-07-27T10:00:00Z'),
    );
}

it('computes subtotal and total including the delivery fee', function (): void {
    $order = placeOrder();

    // 250000*2 + 350000 = 850000; + 50000 delivery = 900000
    expect($order->subtotal()->minorUnits)->toBe(850000)
        ->and($order->deliveryFee()->minorUnits)->toBe(50000)
        ->and($order->total()->minorUnits)->toBe(900000)
        ->and($order->status())->toBe(OrderStatus::Pending);
});

it('records an order-placed event', function (): void {
    $events = placeOrder()->releaseEvents();
    expect($events)->toHaveCount(1);
});

it('allows valid status transitions and blocks illegal ones', function (): void {
    $order = placeOrder();
    $order->transitionTo(OrderStatus::Confirmed, new DateTimeImmutable());

    expect($order->status())->toBe(OrderStatus::Confirmed)
        ->and($order->statusHistory())->toHaveCount(2);

    // Cannot jump Confirmed -> Delivered.
    expect(fn () => $order->transitionTo(OrderStatus::Delivered, new DateTimeImmutable()))
        ->toThrow(MarketplaceInvalidState::class);
});

it('cancels a non-terminal order but not a delivered one', function (): void {
    $order = placeOrder();
    $order->cancel(new DateTimeImmutable());
    expect($order->status())->toBe(OrderStatus::Cancelled);

    expect(fn () => $order->cancel(new DateTimeImmutable()))->toThrow(MarketplaceInvalidState::class);
});

it('rejects an empty order and a delivery order without an address', function (): void {
    expect(fn () => Order::place('o', 'r', 'u', 'v', [], ngn(0), FulfilmentType::Delivery, null, null, null, new DateTimeImmutable()))
        ->toThrow(MarketplaceInvalidState::class);
});
