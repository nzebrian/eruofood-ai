<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\PublicApi\Domain\OAuth\AuthorizationCode;
use EruoFood\PublicApi\Domain\OAuth\AuthorizationCodeRepository;
use EruoFood\PublicApi\Domain\ValueObject\ScopeSet;
use EruoFood\PublicApi\Infrastructure\Persistence\Eloquent\Model\OAuthAuthorizationCodeModel;
use Illuminate\Support\Str;

final class EloquentAuthorizationCodeRepository implements AuthorizationCodeRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findByHash(string $hashedCode): ?AuthorizationCode
    {
        $m = OAuthAuthorizationCodeModel::query()->where('hashed_code', $hashedCode)->first();

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function save(AuthorizationCode $code): void
    {
        $m = OAuthAuthorizationCodeModel::query()->find($code->id()) ?? new OAuthAuthorizationCodeModel();
        $m->id = $code->id();
        $m->hashed_code = $code->hashedCode();
        $m->client_id = $code->clientId();
        $m->subject_user_id = $code->subjectUserId();
        $m->redirect_uri = $code->redirectUri();
        $m->scopes = $code->scopes()->toArray();
        $m->code_challenge = $code->codeChallenge();
        $m->code_challenge_method = $code->codeChallengeMethod();
        $m->expires_at = $code->expiresAt();
        $m->consumed_at = $code->consumedAt();
        $m->save();
    }

    private function toDomain(OAuthAuthorizationCodeModel $m): AuthorizationCode
    {
        return AuthorizationCode::reconstitute(
            $m->id,
            $m->hashed_code,
            $m->client_id,
            $m->subject_user_id,
            $m->redirect_uri,
            ScopeSet::fromArray($m->scopes ?? []),
            $m->code_challenge,
            $m->code_challenge_method,
            DateTimeImmutable::createFromInterface($m->expires_at),
            $m->consumed_at !== null ? DateTimeImmutable::createFromInterface($m->consumed_at) : null,
        );
    }
}
