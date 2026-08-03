<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $user_id
 * @property string $subject_type
 * @property string $subject_id
 * @property DateTimeInterface $created_at
 */
final class PurchaseEligibilityModel extends Model
{
    protected $table = 'review_purchase_eligibility';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
