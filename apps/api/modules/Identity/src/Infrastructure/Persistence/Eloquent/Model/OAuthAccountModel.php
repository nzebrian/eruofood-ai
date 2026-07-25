<?php

declare(strict_types=1);

namespace EruoFood\Identity\Infrastructure\Persistence\Eloquent\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $user_id
 * @property string $provider
 * @property string $provider_user_id
 */
final class OAuthAccountModel extends Model
{
    protected $table = 'identity_oauth_accounts';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
