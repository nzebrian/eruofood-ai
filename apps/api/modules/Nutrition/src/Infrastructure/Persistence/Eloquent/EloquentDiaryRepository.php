<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Nutrition\Domain\Diary\DiaryEntry;
use EruoFood\Nutrition\Domain\Diary\DiaryRepository;
use EruoFood\Nutrition\Domain\Enum\MealType;
use EruoFood\Nutrition\Domain\ValueObject\NutritionFacts;
use EruoFood\Nutrition\Infrastructure\Persistence\Eloquent\Model\DiaryEntryModel;
use Illuminate\Support\Str;

final class EloquentDiaryRepository implements DiaryRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?DiaryEntry
    {
        $model = DiaryEntryModel::query()->find($id);

        return $model !== null ? $this->toDomain($model) : null;
    }

    public function forUserAndDate(string $userId, string $date): array
    {
        return array_values(array_map(
            fn (DiaryEntryModel $m): DiaryEntry => $this->toDomain($m),
            DiaryEntryModel::query()
                ->where('user_id', $userId)
                ->where('entry_date', $date)
                ->orderBy('created_at')
                ->get()
                ->all(),
        ));
    }

    public function save(DiaryEntry $entry): void
    {
        $model = DiaryEntryModel::query()->find($entry->id()) ?? new DiaryEntryModel();
        $model->id = $entry->id();
        $model->user_id = $entry->userId();
        $model->entry_date = $entry->date();
        $model->meal_type = $entry->mealType()->value;
        $model->item_name = $entry->itemName();
        $model->servings = $entry->servings();
        $model->nutrition_item_id = $entry->nutritionItemId();
        $model->nutrition = $entry->facts()->toArray();
        $model->created_at = $entry->loggedAt();
        $model->save();
    }

    public function delete(string $id): void
    {
        DiaryEntryModel::query()->where('id', $id)->delete();
    }

    private function toDomain(DiaryEntryModel $m): DiaryEntry
    {
        return DiaryEntry::create(
            id: $m->id,
            userId: $m->user_id,
            date: $m->entry_date,
            mealType: MealType::from($m->meal_type),
            itemName: $m->item_name,
            servings: $m->servings,
            facts: NutritionFacts::fromArray($m->nutrition ?? []),
            loggedAt: DateTimeImmutable::createFromInterface($m->created_at),
            nutritionItemId: $m->nutrition_item_id,
        );
    }
}
