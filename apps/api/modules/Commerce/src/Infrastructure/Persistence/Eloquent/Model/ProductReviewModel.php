<?php

declare(strict_types=1);

namespace EruoFood\Commerce\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $product_id
 * @property string $user_id
 * @property int $rating
 * @property string|null $comment
 * @property \Illuminate\Support\Carbon $created_at
 */
final class ProductReviewModel extends Model
{
    protected $table = 'commerce_product_reviews';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['rating' => 'integer'];
    }
}
