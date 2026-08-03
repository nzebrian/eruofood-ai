<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\PublicApi\Domain\Enum\OAuthGrant;
use EruoFood\PublicApi\Domain\OAuth\OAuthClient;
use EruoFood\PublicApi\Domain\OAuth\OAuthClientRepository;
use EruoFood\PublicApi\Domain\ValueObject\ScopeSet;
use EruoFood\PublicApi\Infrastructure\Persistence\Eloquent\Model\OAuthClientModel;
use Illuminate\Support\Str;

final class EloquentOAuthClientRepository implements OAuthClientRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?OAuthClient
    {
        $m = OAuthClientModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function save(OAuthClient $client): void
    {
        $m = OAuthClientModel::query()->find($client->id()) ?? new OAuthClientModel();
        $m->id = $client->id();
        $m->application_id = $client->applicationId();
        $m->developer_id = $client->developerId();
        $m->name = $client->name();
        $m->hashed_secret = $client->hashedSecret();
        $m->confidential = $client->isConfidential();
        $m->grants = array_map(static fn (OAuthGrant $g): string => $g->value, $client->grants());
        $m->redirect_uris = $client->redirectUris();
        $m->allowed_scopes = $client->allowedScopes()->toArray();
        $m->created_at = $client->createdAt();
        $m->save();
    }

    private function toDomain(OAuthClientModel $m): OAuthClient
    {
        /** @var list<OAuthGrant> $grants */
        $grants = array_values(array_filter(array_map(
            static fn (string $g): ?OAuthGrant => OAuthGrant::tryFrom($g),
            array_map('strval', (array) ($m->grants ?? [])),
        )));

        return OAuthClient::reconstitute(
            $m->id,
            $m->application_id,
            $m->developer_id,
            $m->name,
            $m->hashed_secret,
            (bool) $m->confidential,
            $grants,
            array_map('strval', (array) ($m->redirect_uris ?? [])),
            ScopeSet::fromArray($m->allowed_scopes ?? []),
            DateTimeImmutable::createFromInterface($m->created_at),
        );
    }
}
