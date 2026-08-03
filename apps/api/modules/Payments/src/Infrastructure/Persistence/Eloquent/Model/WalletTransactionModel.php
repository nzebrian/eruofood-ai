<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $wallet_id
 * @property string $type
 * @property string $direction
 * @property int $amount_minor
 * @property int $balance_after_minor
 * @property string $currency
 * @property string|null $reference
 * @property string|null $description
 * @property DateTimeInterface $created_at
 */
final class WalletTransactionModel extends Model
{
    protected $table = 'payments_wallet_transactions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'balance_after_minor' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
