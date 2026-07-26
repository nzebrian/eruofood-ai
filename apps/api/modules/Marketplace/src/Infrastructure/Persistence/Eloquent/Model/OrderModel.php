<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $reference
 * @property string $customer_user_id
 * @property string $vendor_id
 * @property list<array<string, mixed>> $lines
 * @property int $subtotal_minor
 * @property int $delivery_fee_minor
 * @property int $total_minor
 * @property string $currency
 * @property string $fulfilment
 * @property array<string, mixed>|null $delivery_address
 * @property \Illuminate\Support\Carbon|null $scheduled_for
 * @property string|null $note
 * @property string $status
 * @property list<array{status: string, at: string}> $status_history
 * @property \Illuminate\Support\Carbon $placed_at
 */
final class OrderModel extends Model
{
    protected $table = 'marketplace_orders';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'subtotal_minor' => 'integer',
            'delivery_fee_minor' => 'integer',
            'total_minor' => 'integer',
            'delivery_address' => 'array',
            'status_history' => 'array',
            'scheduled_for' => 'datetime',
            'placed_at' => 'datetime',
        ];
    }
}
