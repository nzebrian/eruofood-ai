<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Config;

/** Persistence port for the {@see Setting} aggregate. */
interface SettingRepository
{
    public function findByKey(string $key): ?Setting;

    /**
     * All settings, optionally narrowed to a group.
     *
     * @return list<Setting>
     */
    public function all(?string $group = null): array;

    public function save(Setting $setting): void;
}
