<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

final class OAuthClientModel extends Model
{
    protected $table = 'oauth_clients';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'confidential' => 'boolean',
            'grants' => 'array',
            'redirect_uris' => 'array',
            'allowed_scopes' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
