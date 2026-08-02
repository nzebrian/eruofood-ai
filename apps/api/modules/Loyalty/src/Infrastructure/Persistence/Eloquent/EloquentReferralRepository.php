<?php

declare(strict_types=1);

namespace EruoFood\Loyalty\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Loyalty\Domain\Enum\ReferralStatus;
use EruoFood\Loyalty\Domain\Referral\Referral;
use EruoFood\Loyalty\Domain\Referral\ReferralCode;
use EruoFood\Loyalty\Domain\Referral\ReferralRepository;
use EruoFood\Loyalty\Infrastructure\Persistence\Eloquent\Model\ReferralCodeModel;
use EruoFood\Loyalty\Infrastructure\Persistence\Eloquent\Model\ReferralModel;
use Illuminate\Support\Str;

final class EloquentReferralRepository implements ReferralRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function generateCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (ReferralCodeModel::query()->whereKey($code)->exists());

        return $code;
    }

    public function findCodeByUser(string $userId): ?ReferralCode
    {
        $m = ReferralCodeModel::query()->where('user_id', $userId)->first();

        return $m !== null ? $this->codeToDomain($m) : null;
    }

    public function findCodeByCode(string $code): ?ReferralCode
    {
        $m = ReferralCodeModel::query()->find($code);

        return $m !== null ? $this->codeToDomain($m) : null;
    }

    public function saveCode(ReferralCode $code): void
    {
        $model = ReferralCodeModel::query()->find($code->code) ?? new ReferralCodeModel();
        $model->code = $code->code;
        $model->user_id = $code->userId;
        $model->created_at = $code->createdAt;
        $model->save();
    }

    public function hasReferee(string $refereeUserId): bool
    {
        return ReferralModel::query()->where('referee_user_id', $refereeUserId)->exists();
    }

    public function pendingByReferee(string $refereeUserId): ?Referral
    {
        $m = ReferralModel::query()
            ->where('referee_user_id', $refereeUserId)
            ->where('status', ReferralStatus::Pending->value)
            ->first();

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function save(Referral $referral): void
    {
        $model = ReferralModel::query()->find($referral->id()) ?? new ReferralModel();
        $model->id = $referral->id();
        $model->code = $referral->code();
        $model->referrer_user_id = $referral->referrerUserId();
        $model->referee_user_id = $referral->refereeUserId();
        $model->status = $referral->status()->value;
        $model->created_at = $referral->createdAt();
        $model->qualified_at = $referral->qualifiedAt();
        $model->save();
    }

    private function codeToDomain(ReferralCodeModel $m): ReferralCode
    {
        return new ReferralCode($m->code, $m->user_id, DateTimeImmutable::createFromInterface($m->created_at));
    }

    private function toDomain(ReferralModel $m): Referral
    {
        return Referral::reconstitute(
            $m->id,
            $m->code,
            $m->referrer_user_id,
            $m->referee_user_id,
            ReferralStatus::from($m->status),
            DateTimeImmutable::createFromInterface($m->created_at),
            $m->qualified_at !== null ? DateTimeImmutable::createFromInterface($m->qualified_at) : null,
        );
    }
}
