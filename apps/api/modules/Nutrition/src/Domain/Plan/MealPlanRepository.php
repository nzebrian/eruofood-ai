<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Domain\Plan;

use EruoFood\Shared\Domain\Paginated;

/** Persistence port for meal plans (Repository Pattern). */
interface MealPlanRepository
{
    public function nextIdentity(): string;

    public function findById(string $id): ?MealPlan;

    /**
     * @return Paginated<MealPlan>
     */
    public function forUser(string $userId, int $page, int $perPage): Paginated;

    public function save(MealPlan $plan): void;

    public function delete(string $id): void;
}
