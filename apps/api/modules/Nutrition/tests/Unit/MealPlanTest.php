<?php

declare(strict_types=1);

use EruoFood\Nutrition\Domain\Enum\MealType;
use EruoFood\Nutrition\Domain\Enum\PlanPeriod;
use EruoFood\Nutrition\Domain\Plan\MealPlan;
use EruoFood\Nutrition\Domain\Plan\MealPlanEntry;

function samplePlan(): MealPlan
{
    return MealPlan::create(
        id: 'p1',
        userId: 'u1',
        title: 'Week 1',
        period: PlanPeriod::Weekly,
        startDate: '2026-07-27',
        entries: [
            new MealPlanEntry('2026-07-27', MealType::Breakfast, 'Akara', 1.0, null, 300.0),
            new MealPlanEntry('2026-07-27', MealType::Lunch, 'Jollof Rice', 2.0, null, 800.0),
        ],
        now: new DateTimeImmutable('2026-07-26T10:00:00Z'),
    );
}

it('rolls up estimated cost across entries', function (): void {
    expect(samplePlan()->estimatedCost())->toBe(1100.0);
});

it('scales all portions and their costs on adjustment', function (): void {
    $plan = samplePlan();
    $plan->adjustPortions(2.0);

    expect($plan->entries()[0]->servings)->toBe(2.0)
        ->and($plan->entries()[1]->servings)->toBe(4.0)
        ->and($plan->estimatedCost())->toBe(2200.0);
});

it('exposes the period day span', function (): void {
    expect(PlanPeriod::Weekly->days())->toBe(7)
        ->and(PlanPeriod::Monthly->days())->toBe(30)
        ->and(PlanPeriod::Daily->days())->toBe(1);
});
