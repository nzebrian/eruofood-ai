<?php

declare(strict_types=1);

namespace EruoFood\Geo\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $user_id
 * @property string $location_id
 * @property string $label
 * @property string|null $custom_name
 * @property string|null $delivery_instructions
 * @property string|null $contact_phone
 * @property bool $is_default
 * @property bool $is_active
 * @property DateTimeInterface|null $last_used_at
 * @property DateTimeInterface $created_at
 * @property DateTimeInterface $updated_at
 */
final class CustomerAddressModel extends Model
{
    protected $table = 'geo_customer_addresses';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
