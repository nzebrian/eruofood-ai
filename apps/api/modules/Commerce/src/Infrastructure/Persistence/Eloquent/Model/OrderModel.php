<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $reference
 * @property string $customer_user_id
 * @property list<string> $store_ids
 * @property list<array<string, mixed>> $lines
 * @property int $subtotal_minor
 * @property int $discount_minor
 * @property int $tax_minor
 * @property int $shipping_minor
 * @property int $total_minor
 * @property string $currency
 * @property string|null $coupon_code
 * @property bool $pickup
 * @property array<array-key, mixed>|null $shipping_address
 * @property DateTimeInterface|null $scheduled_for
 * @property string|null $note
 * @property string $status
 * @property list<array{status: string, at: string}> $status_history
 * @property DateTimeInterface $placed_at
 */
final class OrderModel extends Model
{
    protected $table = 'commerce_orders';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'store_ids' => 'array',
            'lines' => 'array',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'shipping_minor' => 'integer',
            'total_minor' => 'integer',
            'pickup' => 'boolean',
            'shipping_address' => 'array',
            'scheduled_for' => 'datetime',
            'status_history' => 'array',
            'placed_at' => 'datetime',
        ];
    }
}
