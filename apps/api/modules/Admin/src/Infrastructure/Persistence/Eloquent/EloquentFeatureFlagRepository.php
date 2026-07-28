<?php

declare(strict_types=1);

namespace EruoFood\Admin\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Admin\Domain\Config\FeatureFlag;
use EruoFood\Admin\Domain\Config\FeatureFlagRepository;
use EruoFood\Admin\Infrastructure\Persistence\Eloquent\Model\FeatureFlagModel;

final class EloquentFeatureFlagRepository implements FeatureFlagRepository
{
    public function findByKey(string $key): ?FeatureFlag
    {
        $m = FeatureFlagModel::query()->find($key);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function all(): array
    {
        return array_map(
            fn (FeatureFlagModel $m): FeatureFlag => $this->toDomain($m),
            FeatureFlagModel::query()->orderBy('key')->get()->all(),
        );
    }

    public function save(FeatureFlag $flag): void
    {
        $model = FeatureFlagModel::query()->find($flag->key()) ?? new FeatureFlagModel();
        $model->key = $flag->key();
        $model->enabled = $flag->isEnabled();
        $model->description = $flag->description();
        $model->updated_at = $flag->updatedAt();
        $model->save();
    }

    private function toDomain(FeatureFlagModel $m): FeatureFlag
    {
        return FeatureFlag::reconstitute(
            key: $m->key,
            enabled: (bool) $m->enabled,
            description: $m->description,
            updatedAt: DateTimeImmutable::createFromInterface($m->updated_at),
        );
    }
}
