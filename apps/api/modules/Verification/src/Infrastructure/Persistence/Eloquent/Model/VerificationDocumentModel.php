<?php

declare(strict_types=1);

namespace EruoFood\Verification\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $case_id
 * @property string $document_type
 * @property string|null $issuing_country
 * @property string|null $number_last4
 * @property DateTimeInterface|null $expires_on
 * @property string|null $provider_reference
 * @property DateTimeInterface $created_at
 */
final class VerificationDocumentModel extends Model
{
    protected $table = 'verification_documents';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_on' => 'date',
            'created_at' => 'datetime',
        ];
    }
}
