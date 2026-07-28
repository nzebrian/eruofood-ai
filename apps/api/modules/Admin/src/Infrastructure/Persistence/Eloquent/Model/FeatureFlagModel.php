<?php

declare(strict_types=1);

namespace EruoFood\Admin\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

final class FeatureFlagModel extends Model
{
    protected $table = 'admin_feature_flags';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'updated_at' => 'datetime',
        ];
    }
}
