<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Infrastructure\Persistence\Eloquent;

use EruoFood\Marketplace\Domain\Cart\Cart;
use EruoFood\Marketplace\Domain\Cart\CartItem;
use EruoFood\Marketplace\Domain\Cart\CartRepository;
use EruoFood\Marketplace\Infrastructure\Persistence\Eloquent\Model\CartModel;

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
            fn (array $i): CartItem => CartItem::fromArray($i, $this->currency),
            $m->items ?? [],
        );

        return Cart::reconstitute($m->user_id, $m->vendor_id, $items, $this->currency);
    }

    public function save(Cart $cart): void
    {
        $model = CartModel::query()->find($cart->userId()) ?? new CartModel();
        $model->user_id = $cart->userId();
        $model->vendor_id = $cart->vendorId();
        $model->items = array_map(static fn (CartItem $i): array => $i->toArray(), $cart->items());
        $model->currency = $cart->currency();
        $model->save();
    }

    public function clear(string $userId): void
    {
        CartModel::query()->where('user_id', $userId)->delete();
    }
}
