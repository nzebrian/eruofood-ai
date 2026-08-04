<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $name
 * @property string|null $code
 * @property array<array-key, mixed>|null $address
 */
final class WarehouseModel extends Model
{
    protected $table = 'commerce_warehouses';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['address' => 'array'];
    }
}
