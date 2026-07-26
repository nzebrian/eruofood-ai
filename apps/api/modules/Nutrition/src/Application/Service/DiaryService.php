<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Application\Service;

use EruoFood\Nutrition\Application\DTO\DailyNutritionSummary;
use EruoFood\Nutrition\Application\Input\DiaryEntryInput;
use EruoFood\Nutrition\Domain\Diary\DiaryEntry;
use EruoFood\Nutrition\Domain\Diary\DiaryRepository;
use EruoFood\Nutrition\Domain\Exception\NutritionNotFound;
use EruoFood\Nutrition\Domain\Item\NutritionItemRepository;
use EruoFood\Nutrition\Domain\Service\NutritionCalculator;
use EruoFood\Nutrition\Domain\ValueObject\NutritionFacts;
use EruoFood\Shared\Domain\Clock;

/**
 * Daily nutrient tracking: log foods and read a day's running totals against the
 * user's targets. Logged nutrition is snapshotted (see {@see DiaryEntry}).
 */
final readonly class DiaryService
{
    public function __construct(
        private DiaryRepository $diary,
        private NutritionItemRepository $items,
        private HealthProfileService $profiles,
        private NutritionCalculator $calculator,
        private Clock $clock,
    ) {
    }

    /** Log a food against a day + meal, resolving nutrition from an item or a custom panel. */
    public function log(string $userId, DiaryEntryInput $input): DiaryEntry
    {
        if ($input->nutritionItemId !== null) {
            $item = $this->items->findById($input->nutritionItemId)
                ?? throw NutritionNotFound::of('nutrition item', $input->nutritionItemId);
            $name = $item->name();
            $facts = $item->factsForServings($input->servings);
        } else {
            $name = $input->itemName ?? 'Custom food';
            $facts = ($input->facts ?? NutritionFacts::empty())->scale($input->servings);
        }

        $entry = DiaryEntry::create(
            id: $this->diary->nextIdentity(),
            userId: $userId,
            date: $input->date,
            mealType: $input->mealType,
            itemName: $name,
            servings: $input->servings,
            facts: $facts,
            loggedAt: $this->clock->now(),
            nutritionItemId: $input->nutritionItemId,
        );
        $this->diary->save($entry);

        return $entry;
    }

    /** A day's entries, summed totals, and (if a profile exists) targets. */
    public function day(string $userId, string $date): DailyNutritionSummary
    {
        $entries = $this->diary->forUserAndDate($userId, $date);

        $totals = NutritionFacts::empty();
        foreach ($entries as $entry) {
            $totals = $totals->add($entry->facts());
        }

        $profile = $this->profiles->get($userId);
        $targets = $profile !== null ? $this->calculator->assess($profile) : null;

        return new DailyNutritionSummary($date, $entries, $totals, $targets);
    }

    /** @throws NutritionNotFound */
    public function delete(string $userId, string $id): void
    {
        $entry = $this->diary->findById($id);
        if ($entry === null || $entry->userId() !== $userId) {
            throw NutritionNotFound::of('diary entry', $id);
        }
        $this->diary->delete($id);
    }
}
