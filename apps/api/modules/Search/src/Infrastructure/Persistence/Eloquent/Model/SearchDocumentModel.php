<?php

declare(strict_types=1);

namespace EruoFood\Search\Infrastructure\Persistence\Eloquent\Model;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $type
 * @property string $source_id
 * @property string $title
 * @property string|null $description
 * @property string|null $search_text
 * @property array<array-key, mixed> $keywords
 * @property string|null $url
 * @property string|null $image
 * @property string $locale
 * @property array<array-key, mixed> $facets
 * @property string|null $region
 * @property string|null $cuisine
 * @property string|null $category
 * @property string|null $difficulty
 * @property string|null $restaurant_id
 * @property string|null $vendor_id
 * @property int $popularity
 * @property float $rating
 * @property int|null $price_minor
 * @property int|null $prep_time_minutes
 * @property int|null $calories
 * @property float|null $latitude
 * @property float|null $longitude
 * @property array<array-key, mixed> $embedding
 * @property DateTimeInterface $created_at
 * @property DateTimeInterface $updated_at
 * @property mixed $embedding_vec
 */
final class SearchDocumentModel extends Model
{
    protected $table = 'search_documents';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'facets' => 'array',
            'embedding' => 'array',
            'latitude' => 'float',
            'longitude' => 'float',
            'popularity' => 'integer',
            'rating' => 'float',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
