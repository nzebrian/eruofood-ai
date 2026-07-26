<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Application\Service;

use EruoFood\Nutrition\Application\DTO\DailyNutritionSummary;
use EruoFood\Nutrition\Domain\Diary\DiaryEntry;
use EruoFood\Nutrition\Domain\Health\HealthProfile;
use EruoFood\Nutrition\Domain\Item\NutritionItem;
use EruoFood\Nutrition\Domain\Plan\MealPlan;
use EruoFood\Nutrition\Domain\Plan\MealPlanEntry;
use EruoFood\Nutrition\Domain\Progress\ProgressEntry;

/** Maps Nutrition aggregates and DTOs to API-shaped arrays. */
final readonly class NutritionPresenter
{
    /** @return array<string, mixed> */
    public function profile(HealthProfile $p): array
    {
        return [
            'user_id' => $p->userId(),
            'weight_kg' => $p->weightKg(),
            'height_cm' => $p->heightCm(),
            'age' => $p->age(),
            'gender' => $p->gender()->value,
            'activity_level' => $p->activityLevel()->value,
            'goal' => $p->goal()->value,
            'dietary_preferences' => $p->dietaryPreferences(),
            'allergies' => $p->allergies(),
            'medical_restrictions' => $p->medicalRestrictions(),
            'updated_at' => $p->updatedAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function item(NutritionItem $i): array
    {
        return [
            'id' => $i->id(),
            'name' => $i->name(),
            'slug' => (string) $i->slug(),
            'category' => $i->category(),
            'serving_size' => $i->servingSize()->toArray(),
            'nutrition' => $i->facts()->toArray(),
            'food_id' => $i->foodId(),
        ];
    }

    /** @return array<string, mixed> */
    public function diaryEntry(DiaryEntry $e): array
    {
        return [
            'id' => $e->id(),
            'date' => $e->date(),
            'meal_type' => $e->mealType()->value,
            'item_name' => $e->itemName(),
            'servings' => $e->servings(),
            'nutrition_item_id' => $e->nutritionItemId(),
            'nutrition' => $e->facts()->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    public function dailySummary(DailyNutritionSummary $s): array
    {
        return [
            'date' => $s->date,
            'entries' => array_map(fn (DiaryEntry $e): array => $this->diaryEntry($e), $s->entries),
            'totals' => $s->totals->toArray(),
            'targets' => $s->targets?->toArray(),
            'remaining_calories' => $s->remainingCalories(),
        ];
    }

    /** @return array<string, mixed> */
    public function mealPlan(MealPlan $p): array
    {
        return [
            'id' => $p->id(),
            'title' => $p->title(),
            'period' => $p->period()->value,
            'start_date' => $p->startDate(),
            'entries' => array_map(static fn (MealPlanEntry $e): array => $e->toArray(), $p->entries()),
            'estimated_cost' => round($p->estimatedCost(), 2),
            'created_at' => $p->createdAt()->format(DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function progress(ProgressEntry $e): array
    {
        return [
            'id' => $e->id(),
            'date' => $e->date(),
            'weight_kg' => $e->weightKg(),
            'note' => $e->note(),
        ];
    }
}
