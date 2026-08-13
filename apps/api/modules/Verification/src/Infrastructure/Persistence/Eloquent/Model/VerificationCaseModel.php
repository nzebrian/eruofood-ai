<?php

declare(strict_types=1);

namespace EruoFood\Verification\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $subject_type
 * @property string $subject_id
 * @property string $case_type
 * @property string $country_code
 * @property string $requested_level
 * @property string $status
 * @property string|null $provider
 * @property string|null $provider_reference
 * @property string|null $decision_reason_code
 * @property string|null $review_note
 * @property DateTimeInterface|null $verified_at
 * @property DateTimeInterface|null $expires_at
 * @property string|null $open_key
 * @property string|null $contact_user_id
 * @property int $version
 * @property DateTimeInterface $created_at
 * @property DateTimeInterface $updated_at
 */
final class VerificationCaseModel extends Model
{
    protected $table = 'verification_cases';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'version' => 'integer',
        ];
    }
}
