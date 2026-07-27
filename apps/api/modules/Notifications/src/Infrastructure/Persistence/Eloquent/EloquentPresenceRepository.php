<?php

declare(strict_types=1);

namespace EruoFood\Notifications\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Notifications\Application\Port\PresenceRepository;
use EruoFood\Notifications\Domain\Enum\PresenceStatus;
use EruoFood\Notifications\Infrastructure\Persistence\Eloquent\Model\PresenceModel;

final class EloquentPresenceRepository implements PresenceRepository
{
    public function set(string $userId, PresenceStatus $status, DateTimeImmutable $at): void
    {
        $model = PresenceModel::query()->find($userId) ?? new PresenceModel();
        $model->user_id = $userId;
        $model->status = $status->value;
        $model->updated_at = $at;
        $model->save();
    }

    public function get(string $userId): PresenceStatus
    {
        $m = PresenceModel::query()->find($userId);

        return $m !== null ? PresenceStatus::from($m->status) : PresenceStatus::Offline;
    }

    public function statuses(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        /** @var array<string, string> $rows */
        $rows = PresenceModel::query()->whereIn('user_id', $userIds)->pluck('status', 'user_id')->all();
        $out = [];
        foreach ($userIds as $id) {
            $out[$id] = $rows[$id] ?? PresenceStatus::Offline->value;
        }

        return $out;
    }
}
