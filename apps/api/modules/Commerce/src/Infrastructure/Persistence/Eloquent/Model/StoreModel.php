<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $owner_user_id
 * @property string $name
 * @property string $slug
 * @property bool $verified
 * @property string|null $description
 * @property string|null $logo
 * @property array<array-key, mixed>|null $address
 * @property string|null $support_email
 * @property string|null $support_phone
 * @property float $rating_average
 * @property int $rating_count
 * @property DateTimeInterface $created_at
 */
final class StoreModel extends Model
{
    protected $table = 'commerce_stores';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'verified' => 'boolean',
            'address' => 'array',
            'rating_average' => 'float',
            'rating_count' => 'integer',
        ];
    }
}
