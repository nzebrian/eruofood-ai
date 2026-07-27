<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Persistence\Eloquent;

use EruoFood\Commerce\Domain\Cart\Cart;
use EruoFood\Commerce\Domain\Cart\CartItem;
use EruoFood\Commerce\Domain\Cart\CartRepository;
use EruoFood\Commerce\Infrastructure\Persistence\Eloquent\Model\CartModel;

final class EloquentCartRepository implements CartRepository
{
    public function __construct(private readonly string $currency)
    {
    }

    public function forUser(string $userId): ?Cart
    {
        $m = CartModel::query()->find($userId);
        if ($m === null) {
            return null;
        }

        $items = array_map(
            fn (array $row): CartItem => CartItem::fromArray($row, $this->currency),
            $m->items ?? [],
        );

        return Cart::reconstitute($userId, array_values($items), $m->coupon_code, $this->currency);
    }

    public function save(Cart $cart): void
    {
        $model = CartModel::query()->find($cart->userId()) ?? new CartModel();
        $model->user_id = $cart->userId();
        $model->coupon_code = $cart->couponCode();
        $model->items = array_map(static fn (CartItem $i): array => $i->toArray(), $cart->items());
        $model->save();
    }

    public function clear(string $userId): void
    {
        CartModel::query()->where('user_id', $userId)->delete();
    }
}
