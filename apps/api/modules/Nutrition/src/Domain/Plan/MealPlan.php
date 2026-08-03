<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Domain\Plan;

use DateTimeImmutable;
use EruoFood\Nutrition\Domain\Enum\PlanPeriod;

/**
 * A daily / weekly / monthly meal plan owned by a user — the consistency
 * boundary for its collection of {@see MealPlanEntry} slots. Entries are value
 * objects; the plan owns operations over them (portion adjustment, cost roll-up)
 * so the collection is only ever mutated through the aggregate root.
 */
final class MealPlan
{
    /**
     * @param list<MealPlanEntry> $entries
     */
    private function __construct(
        private readonly string $id,
        private readonly string $userId,
        private string $title,
        private readonly PlanPeriod $period,
        private readonly string $startDate, // Y-m-d
        private array $entries,
        private readonly DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param list<MealPlanEntry> $entries
     */
    public static function create(
        string $id,
        string $userId,
        string $title,
        PlanPeriod $period,
        string $startDate,
        array $entries,
        DateTimeImmutable $now,
    ): self {
        return new self($id, $userId, $title, $period, $startDate, $entries, $now);
    }

    /**
     * @param list<MealPlanEntry> $entries
     */
    public static function reconstitute(
        string $id,
        string $userId,
        string $title,
        PlanPeriod $period,
        string $startDate,
        array $entries,
        DateTimeImmutable $createdAt,
    ): self {
        return new self($id, $userId, $title, $period, $startDate, $entries, $createdAt);
    }

    /** Scale every entry's portion by a factor (portion adjustment). */
    public function adjustPortions(float $factor): void
    {
        $this->entries = array_map(
            static fn (MealPlanEntry $e): MealPlanEntry => $e->withServings($e->servings * $factor),
            $this->entries,
        );
    }

    public function rename(string $title): void
    {
        $this->title = $title;
    }

    /** Total estimated cost across entries that carry a cost estimate. */
    public function estimatedCost(): float
    {
        return array_reduce(
            $this->entries,
            static fn (float $carry, MealPlanEntry $e): float => $carry + ($e->estimatedCost ?? 0.0),
            0.0,
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function period(): PlanPeriod
    {
        return $this->period;
    }

    public function startDate(): string
    {
        return $this->startDate;
    }

    /** @return list<MealPlanEntry> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
