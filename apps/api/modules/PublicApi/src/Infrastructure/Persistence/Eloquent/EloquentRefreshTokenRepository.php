<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\PublicApi\Domain\OAuth\RefreshToken;
use EruoFood\PublicApi\Domain\OAuth\RefreshTokenRepository;
use EruoFood\PublicApi\Domain\ValueObject\ScopeSet;
use EruoFood\PublicApi\Infrastructure\Persistence\Eloquent\Model\OAuthRefreshTokenModel;
use Illuminate\Support\Str;

final class EloquentRefreshTokenRepository implements RefreshTokenRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findByHash(string $hashedToken): ?RefreshToken
    {
        $m = OAuthRefreshTokenModel::query()->where('hashed_token', $hashedToken)->first();

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function save(RefreshToken $token): void
    {
        $m = OAuthRefreshTokenModel::query()->find($token->id()) ?? new OAuthRefreshTokenModel();
        $m->id = $token->id();
        $m->hashed_token = $token->hashedToken();
        $m->access_token_id = $token->accessTokenId();
        $m->client_id = $token->clientId();
        $m->application_id = $token->applicationId();
        $m->developer_id = $token->developerId();
        $m->subject_user_id = $token->subjectUserId();
        $m->scopes = $token->scopes()->toArray();
        $m->expires_at = $token->expiresAt();
        $m->revoked_at = $token->revokedAt();
        $m->created_at = $token->createdAt();
        $m->save();
    }

    private function toDomain(OAuthRefreshTokenModel $m): RefreshToken
    {
        return RefreshToken::reconstitute(
            $m->id,
            $m->hashed_token,
            $m->access_token_id,
            $m->client_id,
            $m->application_id,
            $m->developer_id,
            $m->subject_user_id,
            ScopeSet::fromArray($m->scopes ?? []),
            DateTimeImmutable::createFromInterface($m->expires_at),
            $m->revoked_at !== null ? DateTimeImmutable::createFromInterface($m->revoked_at) : null,
            DateTimeImmutable::createFromInterface($m->created_at),
        );
    }
}
