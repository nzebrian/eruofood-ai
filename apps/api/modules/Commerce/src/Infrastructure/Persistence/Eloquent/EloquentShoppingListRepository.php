<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Persistence\Eloquent;

use EruoFood\Commerce\Domain\Shopping\ShoppingList;
use EruoFood\Commerce\Domain\Shopping\ShoppingListRepository;
use EruoFood\Commerce\Infrastructure\Persistence\Eloquent\Model\ShoppingListModel;
use Illuminate\Support\Str;

final class EloquentShoppingListRepository implements ShoppingListRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?ShoppingList
    {
        $m = ShoppingListModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function forUser(string $userId): array
    {
        return array_map(
            fn (ShoppingListModel $m): ShoppingList => $this->toDomain($m),
            ShoppingListModel::query()->where('user_id', $userId)->orderByDesc('created_at')->get()->all(),
        );
    }

    public function save(ShoppingList $list): void
    {
        $model = ShoppingListModel::query()->find($list->id()) ?? new ShoppingListModel();
        $model->id = $list->id();
        $model->user_id = $list->userId();
        $model->name = $list->name();
        $model->lines = $list->lines();
        $model->save();
    }

    public function delete(string $id): void
    {
        ShoppingListModel::query()->where('id', $id)->delete();
    }

    /** @return array{name: string, quantity: int, product_id: string|null, bought: bool}[] */
    private function normaliseLines(ShoppingListModel $m): array
    {
        $lines = [];
        foreach ($m->lines ?? [] as $line) {
            $lines[] = [
                'name' => (string) ($line['name'] ?? ''),
                'quantity' => (int) ($line['quantity'] ?? 1),
                'product_id' => isset($line['product_id']) ? (string) $line['product_id'] : null,
                'bought' => (bool) ($line['bought'] ?? false),
            ];
        }

        return $lines;
    }

    private function toDomain(ShoppingListModel $m): ShoppingList
    {
        return ShoppingList::reconstitute(
            id: $m->id,
            userId: $m->user_id,
            name: $m->name,
            lines: $this->normaliseLines($m),
        );
    }
}
