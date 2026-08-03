<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $subject_type
 * @property string $subject_id
 * @property string $author_id
 * @property int $rating
 * @property string|null $title
 * @property string|null $body
 * @property array<array-key, mixed> $photos
 * @property bool $verified_purchase
 * @property string $status
 * @property int $helpful_yes
 * @property int $helpful_no
 * @property array<array-key, mixed>|null $owner_response
 * @property string|null $moderated_by
 * @property string|null $moderation_reason
 * @property DateTimeInterface $created_at
 * @property DateTimeInterface $updated_at
 */
final class ReviewModel extends Model
{
    protected $table = 'reviews';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'photos' => 'array',
            'owner_response' => 'array',
            'verified_purchase' => 'boolean',
            'helpful_yes' => 'integer',
            'helpful_no' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
