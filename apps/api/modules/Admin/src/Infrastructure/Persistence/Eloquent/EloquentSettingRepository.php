<?php

declare(strict_types=1);

namespace EruoFood\Admin\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Admin\Domain\Config\Setting;
use EruoFood\Admin\Domain\Config\SettingRepository;
use EruoFood\Admin\Infrastructure\Persistence\Eloquent\Model\SettingModel;

final class EloquentSettingRepository implements SettingRepository
{
    public function findByKey(string $key): ?Setting
    {
        $m = SettingModel::query()->find($key);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function all(?string $group = null): array
    {
        $builder = SettingModel::query();
        if ($group !== null) {
            $builder->where('group', $group);
        }

        return array_map(
            fn (SettingModel $m): Setting => $this->toDomain($m),
            $builder->orderBy('group')->orderBy('key')->get()->all(),
        );
    }

    public function save(Setting $setting): void
    {
        $model = SettingModel::query()->find($setting->key()) ?? new SettingModel();
        $model->key = $setting->key();
        $model->group = $setting->group();
        $model->value = $setting->value();
        $model->secret = $setting->isSecret();
        $model->description = $setting->description();
        $model->updated_at = $setting->updatedAt();
        $model->save();
    }

    private function toDomain(SettingModel $m): Setting
    {
        return Setting::reconstitute(
            key: $m->key,
            group: $m->group,
            value: (string) $m->value,
            secret: (bool) $m->secret,
            description: $m->description,
            updatedAt: DateTimeImmutable::createFromInterface($m->updated_at),
        );
    }
}
