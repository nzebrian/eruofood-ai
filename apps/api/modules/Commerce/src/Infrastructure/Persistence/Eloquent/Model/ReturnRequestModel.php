<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $order_id
 * @property string $customer_user_id
 * @property string $reason
 * @property int $refund_minor
 * @property string $currency
 * @property string $status
 * @property string|null $resolution_note
 * @property DateTimeInterface $requested_at
 * @property DateTimeInterface|null $resolved_at
 */
final class ReturnRequestModel extends Model
{
    protected $table = 'commerce_return_requests';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'refund_minor' => 'integer',
            'requested_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
