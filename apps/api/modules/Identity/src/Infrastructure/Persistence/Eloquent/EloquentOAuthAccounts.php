<?php

declare(strict_types=1);

namespace EruoFood\Identity\Infrastructure\Persistence\Eloquent;

use EruoFood\Identity\Application\Port\OAuthAccounts;
use EruoFood\Identity\Domain\ValueObject\UserId;
use EruoFood\Identity\Infrastructure\Persistence\Eloquent\Model\OAuthAccountModel;
use Illuminate\Support\Str;

final readonly class EloquentOAuthAccounts implements OAuthAccounts
{
    public function findUserIdByProvider(string $provider, string $providerUserId): ?UserId
    {
        $model = OAuthAccountModel::query()
            ->where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();

        return $model !== null ? new UserId($model->user_id) : null;
    }

    public function link(UserId $userId, string $provider, string $providerUserId): void
    {
        OAuthAccountModel::query()->updateOrCreate(
            ['provider' => $provider, 'provider_user_id' => $providerUserId],
            ['id' => (string) Str::orderedUuid(), 'user_id' => $userId->value()],
        );
    }
}
