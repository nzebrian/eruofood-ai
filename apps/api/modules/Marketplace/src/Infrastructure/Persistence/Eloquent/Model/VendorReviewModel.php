<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $vendor_id
 * @property string $user_id
 * @property int $rating
 * @property string|null $comment
 * @property DateTimeInterface $created_at
 */
final class VendorReviewModel extends Model
{
    protected $table = 'marketplace_vendor_reviews';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['rating' => 'integer'];
    }
}
