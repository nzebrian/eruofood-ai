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
use EruoFood\Shared\Domain\TransactionManager;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * Checkout: turns a user's cart into a placed order (and a delivery job for
 * delivery orders). Items are re-priced and stock is decremented at checkout —
 * the single point where money and inventory are committed — then the cart is
 * cleared.
 *
 * The whole sequence runs in one transaction with every menu item locked. Two
 * things go wrong without it. Stock is read and written back, so two customers
 * ordering the last portion both see it available. And the steps commit
 * independently, so a failure after the third item leaves three portions
 * deducted against an order that was never created.
 *
 * Locks are taken in a fixed id order so two carts sharing items cannot deadlock
 * by grabbing the same rows in opposite sequences.
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
        private TransactionManager $transactions,
        private string $currency,
    ) {
    }

    public function checkout(string $userId, CheckoutInput $input): Order
    {
        $order = $this->transactions->atomic(function () use ($userId, $input): Order {
            $cart = $this->carts->get($userId);
            if ($cart->isEmpty() || $cart->vendorId() === null) {
                throw new MarketplaceInvalidState('Your cart is empty.');
            }

            $vendor = $this->vendors->get($cart->vendorId());
            if (! $vendor->canTrade()) {
                throw new MarketplaceInvalidState('This vendor is not currently accepting orders.');
            }

            // Lock every menu item up front, in a stable order, before any of
            // them is read for pricing or stock.
            $cartItems = $cart->items();
            $itemIds = array_values(array_unique(array_map(
                static fn ($cartItem): string => $cartItem->menuItemId,
                $cartItems,
            )));
            sort($itemIds);

            $locked = [];
            foreach ($itemIds as $itemId) {
                $found = $this->items->findByIdForUpdate($itemId);
                if ($found !== null) {
                    $locked[$itemId] = $found;
                }
            }

            // Re-price and reserve stock at checkout.
            $lines = [];
            $subtotal = new Money(0, $this->currency);
            foreach ($cartItems as $cartItem) {
                $item = $locked[$cartItem->menuItemId] ?? null;
                if ($item === null || ! $item->isOrderable()) {
                    throw new MarketplaceInvalidState(sprintf('"%s" is no longer available.', $cartItem->name));
                }
                $item->reduceStock($cartItem->quantity);

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

            foreach ($locked as $item) {
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

            return $order;
        });

        // Published after commit: no subscriber should see an order that a
        // rollback is about to erase.
        $this->events->publish(...$order->releaseEvents());

        return $order;
    }
}
