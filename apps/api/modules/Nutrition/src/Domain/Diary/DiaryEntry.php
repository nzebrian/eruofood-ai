<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Domain\Diary;

use DateTimeImmutable;
use EruoFood\Nutrition\Domain\Enum\MealType;
use EruoFood\Nutrition\Domain\ValueObject\NutritionFacts;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * One logged food in a user's daily nutrition diary.
 *
 * The nutrition panel is stored as a **snapshot** at log time (not a live
 * reference to a {@see \EruoFood\Nutrition\Domain\Item\NutritionItem}), so a
 * later edit to the item never rewrites history — a day's totals stay exactly
 * what was eaten.
 */
final class DiaryEntry
{
    private function __construct(
        private readonly string $id,
        private readonly string $userId,
        private readonly string $date, // Y-m-d
        private MealType $mealType,
        private string $itemName,
        private float $servings,
        private NutritionFacts $facts,
        private readonly DateTimeImmutable $loggedAt,
        private readonly ?string $nutritionItemId,
    ) {
    }

    public static function create(
        string $id,
        string $userId,
        string $date,
        MealType $mealType,
        string $itemName,
        float $servings,
        NutritionFacts $facts,
        DateTimeImmutable $loggedAt,
        ?string $nutritionItemId = null,
    ): self {
        if ($servings <= 0) {
            throw new InvalidArgumentException('Servings must be greater than zero.');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new InvalidArgumentException('Diary date must be an ISO Y-m-d string.');
        }

        return new self($id, $userId, $date, $mealType, $itemName, $servings, $facts, $loggedAt, $nutritionItemId);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function date(): string
    {
        return $this->date;
    }

    public function mealType(): MealType
    {
        return $this->mealType;
    }

    public function itemName(): string
    {
        return $this->itemName;
    }

    public function servings(): float
    {
        return $this->servings;
    }

    public function facts(): NutritionFacts
    {
        return $this->facts;
    }

    public function loggedAt(): DateTimeImmutable
    {
        return $this->loggedAt;
    }

    public function nutritionItemId(): ?string
    {
        return $this->nutritionItemId;
    }
}
