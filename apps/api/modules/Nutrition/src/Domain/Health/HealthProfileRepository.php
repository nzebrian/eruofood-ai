<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Domain\Health;

/** Persistence port for the per-user health profile (Repository Pattern). */
interface HealthProfileRepository
{
    public function findByUser(string $userId): ?HealthProfile;

    public function save(HealthProfile $profile): void;
}
