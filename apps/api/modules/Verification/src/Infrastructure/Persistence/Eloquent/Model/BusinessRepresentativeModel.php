<?php

declare(strict_types=1);

namespace EruoFood\Verification\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $business_profile_id
 * @property string $user_id
 * @property string $full_name
 * @property string $role
 * @property bool $is_primary
 * @property string|null $identity_case_id
 * @property float|null $ownership_percentage
 * @property DateTimeInterface $created_at
 * @property DateTimeInterface $updated_at
 */
final class BusinessRepresentativeModel extends Model
{
    protected $table = 'verification_business_representatives';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'ownership_percentage' => 'float',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
