<?php

declare(strict_types=1);

namespace EruoFood\Verification\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Verification\Domain\Phone\PhoneChallenge;
use EruoFood\Verification\Domain\Phone\PhoneChallengeRepository;
use EruoFood\Verification\Infrastructure\Persistence\Eloquent\Model\VerificationPhoneChallengeModel;
use Illuminate\Support\Str;

/**
 * One row per account, replaced in place when a new code is requested.
 *
 * A single row rather than a log of challenges: the interesting facts are
 * "is there a live code" and "is the number confirmed", and keeping a history of
 * every code sent would retain phone numbers long after they stop being useful.
 */
final class EloquentPhoneChallengeRepository implements PhoneChallengeRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findForUser(string $userId): ?PhoneChallenge
    {
        $model = VerificationPhoneChallengeModel::query()->where('user_id', $userId)->first();

        return $model === null ? null : $this->toDomain($model);
    }

    public function findForUserForUpdate(string $userId): ?PhoneChallenge
    {
        $model = VerificationPhoneChallengeModel::query()
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();

        return $model === null ? null : $this->toDomain($model);
    }

    public function save(PhoneChallenge $challenge): void
    {
        $model = VerificationPhoneChallengeModel::query()->find($challenge->id())
            ?? new VerificationPhoneChallengeModel();

        $model->id = $challenge->id();
        $model->user_id = $challenge->userId();
        $model->phone = $challenge->phone();
        $model->code_hash = $challenge->codeHash();
        $model->expires_at = $challenge->expiresAt();
        $model->attempts = $challenge->attempts();
        $model->verified_at = $challenge->verifiedAt();
        $model->created_at = $challenge->createdAt();
        $model->updated_at = $challenge->updatedAt();
        $model->save();
    }

    public function isVerified(string $userId): bool
    {
        return VerificationPhoneChallengeModel::query()
            ->where('user_id', $userId)
            ->whereNotNull('verified_at')
            ->exists();
    }

    private function toDomain(VerificationPhoneChallengeModel $model): PhoneChallenge
    {
        return PhoneChallenge::reconstitute(
            id: $model->id,
            userId: $model->user_id,
            phone: $model->phone,
            codeHash: $model->code_hash,
            expiresAt: DateTimeImmutable::createFromInterface($model->expires_at),
            attempts: $model->attempts,
            verifiedAt: $model->verified_at !== null
                ? DateTimeImmutable::createFromInterface($model->verified_at)
                : null,
            createdAt: DateTimeImmutable::createFromInterface($model->created_at),
            updatedAt: DateTimeImmutable::createFromInterface($model->updated_at),
        );
    }
}
