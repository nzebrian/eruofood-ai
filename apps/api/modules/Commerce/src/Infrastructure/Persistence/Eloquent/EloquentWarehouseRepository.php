<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Persistence\Eloquent;

use EruoFood\Commerce\Domain\Inventory\Warehouse;
use EruoFood\Commerce\Domain\Inventory\WarehouseRepository;
use EruoFood\Commerce\Domain\ValueObject\Address;
use EruoFood\Commerce\Infrastructure\Persistence\Eloquent\Model\WarehouseModel;
use Illuminate\Support\Str;

final class EloquentWarehouseRepository implements WarehouseRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?Warehouse
    {
        $m = WarehouseModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function all(): array
    {
        return array_values(array_map(
            fn (WarehouseModel $m): Warehouse => $this->toDomain($m),
            WarehouseModel::query()->orderBy('name')->get()->all(),
        ));
    }

    public function save(Warehouse $warehouse): void
    {
        $model = WarehouseModel::query()->find($warehouse->id()) ?? new WarehouseModel();
        $model->id = $warehouse->id();
        $model->name = $warehouse->name();
        $model->code = $warehouse->code();
        $model->address = $warehouse->address()?->toArray();
        $model->save();
    }

    private function toDomain(WarehouseModel $m): Warehouse
    {
        return Warehouse::reconstitute(
            id: $m->id,
            name: $m->name,
            code: $m->code,
            address: $m->address !== null ? Address::fromArray($m->address) : null,
        );
    }
}
