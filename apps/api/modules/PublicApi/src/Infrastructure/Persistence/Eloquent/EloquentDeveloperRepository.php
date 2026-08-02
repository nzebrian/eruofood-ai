<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\PublicApi\Domain\Developer\Developer;
use EruoFood\PublicApi\Domain\Developer\DeveloperRepository;
use EruoFood\PublicApi\Infrastructure\Persistence\Eloquent\Model\DeveloperModel;
use Illuminate\Support\Str;

final class EloquentDeveloperRepository implements DeveloperRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?Developer
    {
        $m = DeveloperModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function findByUserId(string $userId): ?Developer
    {
        $m = DeveloperModel::query()->where('user_id', $userId)->first();

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function save(Developer $developer): void
    {
        $m = DeveloperModel::query()->find($developer->id()) ?? new DeveloperModel();
        $m->id = $developer->id();
        $m->user_id = $developer->userId();
        $m->name = $developer->name();
        $m->email = $developer->email();
        $m->created_at = $developer->createdAt();
        $m->save();
    }

    private function toDomain(DeveloperModel $m): Developer
    {
        return Developer::reconstitute(
            $m->id,
            $m->user_id,
            $m->name,
            $m->email,
            DateTimeImmutable::createFromInterface($m->created_at),
        );
    }
}
