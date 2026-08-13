<?php

declare(strict_types=1);

namespace EruoFood\Verification\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $business_kind
 * @property string $business_id
 * @property string $country_code
 * @property string $registered_name
 * @property string $trading_name
 * @property string $business_type
 * @property string $registration_number
 * @property string $registration_authority
 * @property array<string, mixed> $address
 * @property float|null $latitude
 * @property float|null $longitude
 * @property string|null $identity_case_id
 * @property string|null $payout_account_case_id
 * @property DateTimeInterface $created_at
 * @property DateTimeInterface $updated_at
 */
final class BusinessProfileModel extends Model
{
    protected $table = 'verification_business_profiles';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'address' => 'array',
            'latitude' => 'float',
            'longitude' => 'float',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
