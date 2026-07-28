<?php

declare(strict_types=1);

namespace EruoFood\Admin\Domain\Config;

/** Persistence port for the {@see FeatureFlag} aggregate. */
interface FeatureFlagRepository
{
    public function findByKey(string $key): ?FeatureFlag;

    /** @return list<FeatureFlag> */
    public function all(): array;

    public function save(FeatureFlag $flag): void;
}
