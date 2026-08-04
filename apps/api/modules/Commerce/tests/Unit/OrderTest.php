<?php

declare(strict_types=1);

use EruoFood\Commerce\Domain\Enum\OrderStatus;
use EruoFood\Commerce\Domain\Exception\CommerceInvalidState;
use EruoFood\Commerce\Domain\Order\Order;
use EruoFood\Commerce\Domain\Order\OrderLine;
use EruoFood\Commerce\Domain\ValueObject\Address;
use EruoFood\Shared\Domain\ValueObject\Money;

function cngn(int $minor): Money
{
    return new Money($minor, 'NGN');
}

function cShipAddress(): Address
{
    return new Address('1 Demo St', null, 'Lagos', 'Lagos', '100001', 'NG');
}

/** @return list<OrderLine> */
function cLines(): array
{
    return [
        new OrderLine('p1', 's1', 'Ofada Rice', null, cngn(500000), 2),
        new OrderLine('p2', 's1', 'Palm Oil', '2L', cngn(420000), 1),
    ];
}

function cPlaceOrder(bool $pickup = false): Order
{
    return Order::place(
        'o1',
        'EF-AB12-CD34',
        'u1',
        cLines(),
        cngn(1420000),
        cngn(100000),
        cngn(99000),
        cngn(150000),
        'WELCOME10',
        $pickup,
        $pickup ? null : cShipAddress(),
        null,
        null,
        new DateTimeImmutable('2026-07-27T10:00:00Z'),
    );
}

it('computes the total as subtotal - discount + tax + shipping', function (): void {
    $order = cPlaceOrder();

    // 1420000 - 100000 + 99000 + 150000 = 1569000
    expect($order->subtotal()->minorUnits)->toBe(1420000)
        ->and($order->discount()->minorUnits)->toBe(100000)
        ->and($order->tax()->minorUnits)->toBe(99000)
        ->and($order->shipping()->minorUnits)->toBe(150000)
        ->and($order->total()->minorUnits)->toBe(1569000)
        ->and($order->status())->toBe(OrderStatus::Pending);
});

it('records an order-placed event on checkout', function (): void {
    expect(cPlaceOrder()->releaseEvents())->toHaveCount(1);
});

it('requires a shipping address unless pickup', function (): void {
    expect(fn () => Order::place(
        'o2',
        'EF-X',
        'u1',
        cLines(),
        cngn(1420000),
        cngn(0),
        cngn(0),
        cngn(0),
        null,
        false,
        null,
        null,
        null,
        new DateTimeImmutable(),
    ))->toThrow(CommerceInvalidState::class);

    $pickup = cPlaceOrder(pickup: true);
    expect($pickup->isPickup())->toBeTrue();
});

it('walks the full status lifecycle and blocks illegal moves', function (): void {
    $order = cPlaceOrder();
    $order->transitionTo(OrderStatus::Paid, new DateTimeImmutable());
    $order->transitionTo(OrderStatus::Processing, new DateTimeImmutable());
    $order->transitionTo(OrderStatus::Shipped, new DateTimeImmutable());
    $order->transitionTo(OrderStatus::Delivered, new DateTimeImmutable());
    expect($order->status())->toBe(OrderStatus::Delivered)
        ->and($order->statusHistory())->toHaveCount(5);

    expect(fn () => $order->cancel(new DateTimeImmutable()))->toThrow(CommerceInvalidState::class);
});

it('cannot place an empty order', function (): void {
    expect(fn () => Order::place(
        'o3',
        'EF-Y',
        'u1',
        [],
        cngn(0),
        cngn(0),
        cngn(0),
        cngn(0),
        null,
        true,
        null,
        null,
        null,
        new DateTimeImmutable(),
    ))->toThrow(CommerceInvalidState::class);
});
