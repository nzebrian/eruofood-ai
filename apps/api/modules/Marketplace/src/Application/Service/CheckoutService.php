<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Application\Service;

use EruoFood\Marketplace\Application\Input\CheckoutInput;
use EruoFood\Marketplace\Application\Port\DeliveryFeeCalculator;
use EruoFood\Marketplace\Domain\Delivery\Delivery;
use EruoFood\Marketplace\Domain\Delivery\DeliveryRepository;
use EruoFood\Marketplace\Domain\Enum\FulfilmentType;
use EruoFood\Marketplace\Domain\Exception\MarketplaceInvalidState;
use EruoFood\Marketplace\Domain\Menu\MenuItemRepository;
use EruoFood\Marketplace\Domain\Order\Order;
use EruoFood\Marketplace\Domain\Order\OrderLine;
use EruoFood\Marketplace\Domain\Order\OrderRepository;
use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * Checkout: turns a user's cart into a placed order (and a delivery job for
 * delivery orders). Items are re-priced and stock is decremented at checkout —
 * the single point where money and inventory are committed — then the cart is
 * cleared. Wrapped in a DB transaction by the caller's repositories.
 */
final readonly class CheckoutService
{
    public function __construct(
        private CartService $carts,
        private VendorService $vendors,
        private MenuItemRepository $items,
        private OrderRepository $orders,
        private DeliveryRepository $deliveries,
        private DeliveryFeeCalculator $fees,
        private EventBus $events,
        private Clock $clock,
        private string $currency,
    ) {
    }

    public function checkout(string $userId, CheckoutInput $input): Order
    {
        $cart = $this->carts->get($userId);
        if ($cart->isEmpty() || $cart->vendorId() === null) {
            throw new MarketplaceInvalidState('Your cart is empty.');
        }

        $vendor = $this->vendors->get($cart->vendorId());
        if (! $vendor->canTrade()) {
            throw new MarketplaceInvalidState('This vendor is not currently accepting orders.');
        }

        // Re-price and reserve stock at checkout.
        $lines = [];
        $subtotal = new Money(0, $this->currency);
        $mutatedItems = [];
        foreach ($cart->items() as $cartItem) {
            $item = $this->items->findById($cartItem->menuItemId);
            if ($item === null || ! $item->isOrderable()) {
                throw new MarketplaceInvalidState(sprintf('"%s" is no longer available.', $cartItem->name));
            }
            $item->reduceStock($cartItem->quantity);
            $mutatedItems[] = $item;

            $unitPrice = $item->priceFor($cartItem->variantName);
            $line = new OrderLine($item->id(), $item->name(), $cartItem->variantName, $unitPrice, $cartItem->quantity);
            $lines[] = $line;
            $subtotal = $subtotal->add($line->lineTotal());
        }

        // Delivery fee (zero for pickup).
        $dropoff = $input->deliveryAddress?->location;
        $deliveryFee = new Money(0, $this->currency);
        $zoneName = null;
        if ($input->fulfilment === FulfilmentType::Delivery) {
            $quote = $this->fees->quote($vendor, $dropoff, $subtotal);
            $deliveryFee = $quote->fee;
            $zoneName = $quote->zoneName;
        }

        $now = $this->clock->now();
        $order = Order::place(
            id: $this->orders->nextIdentity(),
            reference: $this->orders->nextReference(),
            customerUserId: $userId,
            vendorId: $vendor->id(),
            lines: $lines,
            deliveryFee: $deliveryFee,
            fulfilment: $input->fulfilment,
            deliveryAddress: $input->deliveryAddress,
            scheduledFor: $input->scheduledFor,
            note: $input->note,
            now: $now,
        );
        $this->orders->save($order);

        foreach ($mutatedItems as $item) {
            $this->items->save($item);
        }

        if ($input->fulfilment === FulfilmentType::Delivery) {
            $this->deliveries->save(Delivery::create(
                id: $this->deliveries->nextIdentity(),
                orderId: $order->id(),
                vendorId: $vendor->id(),
                fee: $deliveryFee,
                zoneName: $zoneName,
                pickup: $vendor->location(),
                dropoff: $dropoff,
                now: $now,
            ));
        }

        $this->carts->clear($userId);
        $this->events->publish(...$order->releaseEvents());

        return $order;
    }
}
