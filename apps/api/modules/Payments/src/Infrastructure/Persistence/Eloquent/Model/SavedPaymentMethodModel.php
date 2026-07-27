<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

final class SavedPaymentMethodModel extends Model
{
    protected $table = 'payments_saved_methods';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'card' => 'array',
            'is_default' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
