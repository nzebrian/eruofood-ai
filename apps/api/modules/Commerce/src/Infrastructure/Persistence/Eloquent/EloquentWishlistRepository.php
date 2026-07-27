<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Persistence\Eloquent;

use EruoFood\Commerce\Domain\Shopping\Wishlist;
use EruoFood\Commerce\Domain\Shopping\WishlistRepository;
use EruoFood\Commerce\Infrastructure\Persistence\Eloquent\Model\WishlistModel;

final class EloquentWishlistRepository implements WishlistRepository
{
    public function forUser(string $userId): ?Wishlist
    {
        $m = WishlistModel::query()->find($userId);
        if ($m === null) {
            return null;
        }

        return Wishlist::reconstitute($userId, array_map('strval', $m->product_ids ?? []));
    }

    public function save(Wishlist $wishlist): void
    {
        $model = WishlistModel::query()->find($wishlist->userId()) ?? new WishlistModel();
        $model->user_id = $wishlist->userId();
        $model->product_ids = $wishlist->productIds();
        $model->save();
    }
}
