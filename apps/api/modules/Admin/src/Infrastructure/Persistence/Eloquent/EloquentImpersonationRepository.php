<?php

declare(strict_types=1);

namespace EruoFood\Admin\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Admin\Domain\Rbac\Impersonation;
use EruoFood\Admin\Domain\Rbac\ImpersonationRepository;
use EruoFood\Admin\Infrastructure\Persistence\Eloquent\Model\ImpersonationModel;
use Illuminate\Support\Str;

final class EloquentImpersonationRepository implements ImpersonationRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?Impersonation
    {
        $m = ImpersonationModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function activeForAdmin(string $adminUserId): ?Impersonation
    {
        $m = ImpersonationModel::query()
            ->where('admin_user_id', $adminUserId)
            ->whereNull('ended_at')
            ->orderByDesc('started_at')
            ->first();

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function save(Impersonation $impersonation): void
    {
        $model = ImpersonationModel::query()->find($impersonation->id()) ?? new ImpersonationModel();
        $model->id = $impersonation->id();
        $model->admin_user_id = $impersonation->adminUserId();
        $model->target_user_id = $impersonation->targetUserId();
        $model->reason = $impersonation->reason();
        $model->started_at = $impersonation->startedAt();
        $model->ended_at = $impersonation->endedAt();
        $model->save();
    }

    private function toDomain(ImpersonationModel $m): Impersonation
    {
        return Impersonation::reconstitute(
            id: $m->id,
            adminUserId: $m->admin_user_id,
            targetUserId: $m->target_user_id,
            reason: $m->reason,
            startedAt: DateTimeImmutable::createFromInterface($m->started_at),
            endedAt: $m->ended_at !== null ? DateTimeImmutable::createFromInterface($m->ended_at) : null,
        );
    }
}
