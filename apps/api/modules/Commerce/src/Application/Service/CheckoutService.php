<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Application\Service;

use DateTimeImmutable;
use EruoFood\Commerce\Application\DTO\DiscountResult;
use EruoFood\Commerce\Application\DTO\PriceBreakdown;
use EruoFood\Commerce\Application\Input\CheckoutInput;
use EruoFood\Commerce\Application\Port\DiscountEngine;
use EruoFood\Commerce\Application\Port\ShippingCalculator;
use EruoFood\Commerce\Application\Port\TaxCalculator;
use EruoFood\Commerce\Domain\Cart\Cart;
use EruoFood\Commerce\Domain\Cart\CartRepository;
use EruoFood\Commerce\Domain\Exception\CommerceInvalidState;
use EruoFood\Commerce\Domain\Inventory\InventoryItemRepository;
use EruoFood\Commerce\Domain\Order\Order;
use EruoFood\Commerce\Domain\Order\OrderLine;
use EruoFood\Commerce\Domain\Order\OrderRepository;
use EruoFood\Commerce\Domain\Promotion\CouponRepository;
use EruoFood\Shared\Domain\EventBus;
use EruoFood\Shared\Domain\TransactionManager;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * Checkout — the single place that turns a cart into a placed order. It resolves
 * the discount (coupon), computes the money breakdown (subtotal → discount → tax
 * → shipping → total) via the tax and shipping ports, captures priced lines,
 * commits inventory, redeems the coupon, and clears the cart. Pickup orders pay
 * no shipping.
 */
final readonly class CheckoutService
{
    public function __construct(
        private CartRepository $carts,
        private OrderRepository $orders,
        private CouponRepository $coupons,
        private InventoryItemRepository $inventory,
        private DiscountEngine $discounts,
        private TaxCalculator $tax,
        private ShippingCalculator $shipping,
        private EventBus $events,
        private TransactionManager $transactions,
        private string $currency,
    ) {
    }

    /** A read-only price quote for the current cart (no order is created). */
    public function quote(string $userId, bool $pickup): PriceBreakdown
    {
        $cart = $this->carts->forUser($userId) ?? Cart::forUser($userId, $this->currency);
        if ($cart->isEmpty()) {
            throw new CommerceInvalidState('Your cart is empty.');
        }

        return $this->breakdown($cart, $pickup, $this->discounts->evaluate($cart));
    }

    /**
     * Turn the cart into a placed order.
     *
     * Inventory, the order and the coupon counter all commit together. Before
     * M23 each step committed on its own, so a failure after the inventory loop
     * left stock deducted with no order to show for it, and a coupon could be
     * redeemed past its usage cap by two simultaneous checkouts reading the same
     * counter. Stock rows and the coupon are now locked before they are read.
     */
    public function place(string $userId, CheckoutInput $input): Order
    {
        $order = $this->transactions->atomic(function () use ($userId, $input): Order {
            $cart = $this->carts->forUser($userId) ?? Cart::forUser($userId, $this->currency);
            if ($cart->isEmpty()) {
                throw new CommerceInvalidState('Your cart is empty.');
            }

            $discount = $this->discounts->evaluate($cart);
            $breakdown = $this->breakdown($cart, $input->pickup, $discount);

            $lines = array_map(
                static fn ($item): OrderLine => new OrderLine(
                    productId: $item->productId,
                    storeId: $item->storeId,
                    name: $item->name,
                    variantSku: $item->variantSku,
                    unitPrice: $item->unitPrice,
                    quantity: $item->quantity,
                ),
                $cart->items(),
            );

            // Commit inventory before creating the order (fails fast if short).
            // Deduct in a stable product order so concurrent carts sharing
            // products queue behind each other instead of deadlocking.
            $cartItems = $cart->items();
            usort($cartItems, static fn ($a, $b): int => [$a->productId, (string) $a->variantSku] <=> [$b->productId, (string) $b->variantSku]);

            foreach ($cartItems as $item) {
                $stock = $this->inventory->findForProductForUpdate($item->productId, $item->variantSku);
                if ($stock === null) {
                    continue; // untracked product — no stock control
                }
                if (! $stock->hasStock($item->quantity)) {
                    throw new CommerceInvalidState(sprintf('Not enough stock for "%s".', $item->name));
                }
                $stock->deduct($item->quantity);
                $this->inventory->save($stock);
            }

            $order = Order::place(
                id: $this->orders->nextIdentity(),
                reference: $this->orders->nextReference(),
                customerUserId: $userId,
                lines: $lines,
                subtotal: $breakdown->subtotal,
                discount: $breakdown->discount,
                tax: $breakdown->tax,
                shipping: $breakdown->shipping,
                couponCode: $discount->coupon?->code(),
                pickup: $input->pickup,
                shippingAddress: $input->shippingAddress,
                scheduledFor: $input->scheduledFor,
                note: $input->note,
                now: new DateTimeImmutable(),
            );
            $this->orders->save($order);

            if ($discount->coupon !== null) {
                // Re-read under lock: the redemption counter checked during
                // evaluate() may have moved since.
                $coupon = $this->coupons->findByCodeForUpdate($discount->coupon->code()) ?? $discount->coupon;
                $coupon->redeem();
                $this->coupons->save($coupon);
            }

            $this->carts->clear($userId);

            return $order;
        });

        foreach ($order->releaseEvents() as $event) {
            $this->events->publish($event);
        }

        return $order;
    }

    private function breakdown(Cart $cart, bool $pickup, DiscountResult $discount): PriceBreakdown
    {
        $subtotal = $cart->subtotal();
        $discountAmount = $discount->amount;
        $taxable = $subtotal->subtract($discountAmount);
        if ($taxable->minorUnits < 0) {
            $taxable = new Money(0, $this->currency);
        }
        $tax = $this->tax->taxFor($taxable);

        $shipping = $pickup || $discount->freeShipping
            ? new Money(0, $this->currency)
            : $this->shipping->shippingFor($taxable, $cart->itemCount());

        $total = $taxable->add($tax)->add($shipping);

        return new PriceBreakdown($subtotal, $discountAmount, $tax, $shipping, $total);
    }
}
