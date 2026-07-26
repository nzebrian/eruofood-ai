<?php

declare(strict_types=1);

namespace EruoFood\Marketplace\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $owner_user_id
 * @property string $name
 * @property string $slug
 * @property string $type
 * @property string $status
 * @property string $category
 * @property string|null $description
 * @property array<string, mixed> $contact
 * @property array<string, mixed> $address
 * @property list<array<string, mixed>> $branches
 * @property array<int, array{open: string, close: string}> $business_hours
 * @property list<array<string, mixed>> $delivery_zones
 * @property list<string> $images
 * @property bool $featured
 * @property float $rating_average
 * @property int $rating_count
 * @property float|null $latitude
 * @property float|null $longitude
 * @property \Illuminate\Support\Carbon $created_at
 */
final class VendorModel extends Model
{
    protected $table = 'marketplace_vendors';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'contact' => 'array',
            'address' => 'array',
            'branches' => 'array',
            'business_hours' => 'array',
            'delivery_zones' => 'array',
            'images' => 'array',
            'featured' => 'boolean',
            'rating_average' => 'float',
            'rating_count' => 'integer',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }
}
