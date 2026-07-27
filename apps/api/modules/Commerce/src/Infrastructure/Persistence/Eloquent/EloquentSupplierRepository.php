<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Persistence\Eloquent;

use EruoFood\Commerce\Domain\Inventory\Supplier;
use EruoFood\Commerce\Domain\Inventory\SupplierRepository;
use EruoFood\Commerce\Infrastructure\Persistence\Eloquent\Model\SupplierModel;
use Illuminate\Support\Str;

final class EloquentSupplierRepository implements SupplierRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?Supplier
    {
        $m = SupplierModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function all(): array
    {
        return array_map(
            fn (SupplierModel $m): Supplier => $this->toDomain($m),
            SupplierModel::query()->orderBy('name')->get()->all(),
        );
    }

    public function save(Supplier $supplier): void
    {
        $model = SupplierModel::query()->find($supplier->id()) ?? new SupplierModel();
        $model->id = $supplier->id();
        $model->name = $supplier->name();
        $model->contact_name = $supplier->contactName();
        $model->email = $supplier->email();
        $model->phone = $supplier->phone();
        $model->save();
    }

    private function toDomain(SupplierModel $m): Supplier
    {
        return Supplier::reconstitute(
            id: $m->id,
            name: $m->name,
            contactName: $m->contact_name,
            email: $m->email,
            phone: $m->phone,
        );
    }
}
