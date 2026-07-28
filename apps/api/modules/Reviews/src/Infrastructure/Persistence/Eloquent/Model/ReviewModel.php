<?php

declare(strict_types=1);

namespace EruoFood\Reviews\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

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
