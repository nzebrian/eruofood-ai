<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\PublicApi\Domain\ApiKey\ApiKey;
use EruoFood\PublicApi\Domain\ApiKey\ApiKeyRepository;
use EruoFood\PublicApi\Domain\Enum\ApiKeyStatus;
use EruoFood\PublicApi\Domain\ValueObject\ScopeSet;
use EruoFood\PublicApi\Infrastructure\Persistence\Eloquent\Model\ApiKeyModel;
use Illuminate\Support\Str;

final class EloquentApiKeyRepository implements ApiKeyRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?ApiKey
    {
        $m = ApiKeyModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function findByPrefix(string $prefix): ?ApiKey
    {
        $m = ApiKeyModel::query()->where('prefix', $prefix)->first();

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function forApplication(string $applicationId): array
    {
        return array_values(array_map(
            fn (ApiKeyModel $m): ApiKey => $this->toDomain($m),
            ApiKeyModel::query()->where('application_id', $applicationId)->orderByDesc('created_at')->get()->all(),
        ));
    }

    public function save(ApiKey $key): void
    {
        $m = ApiKeyModel::query()->find($key->id()) ?? new ApiKeyModel();
        $m->id = $key->id();
        $m->application_id = $key->applicationId();
        $m->name = $key->name();
        $m->prefix = $key->prefix();
        $m->hashed_secret = $key->hashedSecret();
        $m->scopes = $key->scopes()->toArray();
        $m->status = $key->status()->value;
        $m->expires_at = $key->expiresAt();
        $m->last_used_at = $key->lastUsedAt();
        $m->created_at = $key->createdAt();
        $m->revoked_at = $key->revokedAt();
        $m->subject_user_id = $key->subjectUserId();
        $m->save();
    }

    private function toDomain(ApiKeyModel $m): ApiKey
    {
        return ApiKey::reconstitute(
            $m->id,
            $m->application_id,
            $m->name,
            $m->prefix,
            $m->hashed_secret,
            ScopeSet::fromArray($m->scopes ?? []),
            ApiKeyStatus::from($m->status),
            $m->expires_at !== null ? DateTimeImmutable::createFromInterface($m->expires_at) : null,
            $m->last_used_at !== null ? DateTimeImmutable::createFromInterface($m->last_used_at) : null,
            DateTimeImmutable::createFromInterface($m->created_at),
            $m->revoked_at !== null ? DateTimeImmutable::createFromInterface($m->revoked_at) : null,
            $m->subject_user_id,
        );
    }
}
