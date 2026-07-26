<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Application\Service;

use EruoFood\Nutrition\Application\Input\MealPlanInput;
use EruoFood\Nutrition\Domain\Exception\NutritionNotFound;
use EruoFood\Nutrition\Domain\Plan\MealPlan;
use EruoFood\Nutrition\Domain\Plan\MealPlanRepository;
use EruoFood\Shared\Domain\Clock;
use EruoFood\Shared\Domain\Paginated;

/** Daily / weekly / monthly meal plans with portion adjustment. */
final readonly class MealPlanService
{
    public function __construct(
        private MealPlanRepository $plans,
        private Clock $clock,
    ) {
    }

    public function create(string $userId, MealPlanInput $input): MealPlan
    {
        $plan = MealPlan::create(
            id: $this->plans->nextIdentity(),
            userId: $userId,
            title: $input->title,
            period: $input->period,
            startDate: $input->startDate,
            entries: $input->entries,
            now: $this->clock->now(),
        );
        $this->plans->save($plan);

        return $plan;
    }

    /** @throws NutritionNotFound */
    public function get(string $userId, string $id): MealPlan
    {
        $plan = $this->plans->findById($id);
        if ($plan === null || $plan->userId() !== $userId) {
            throw NutritionNotFound::of('meal plan', $id);
        }

        return $plan;
    }

    /**
     * @return Paginated<MealPlan>
     */
    public function list(string $userId, int $page, int $perPage): Paginated
    {
        return $this->plans->forUser($userId, max(1, $page), min(50, max(1, $perPage)));
    }

    /** Scale every entry's portion by a factor (portion adjustment). */
    public function adjustPortions(string $userId, string $id, float $factor): MealPlan
    {
        $plan = $this->get($userId, $id);
        $plan->adjustPortions($factor);
        $this->plans->save($plan);

        return $plan;
    }

    public function delete(string $userId, string $id): void
    {
        $this->get($userId, $id);
        $this->plans->delete($id);
    }
}
