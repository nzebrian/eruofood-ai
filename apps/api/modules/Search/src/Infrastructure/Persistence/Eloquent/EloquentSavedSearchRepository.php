<?php

declare(strict_types=1);

namespace EruoFood\Search\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use EruoFood\Search\Domain\Enum\SearchType;
use EruoFood\Search\Domain\Enum\SortOption;
use EruoFood\Search\Domain\SavedSearch\SavedSearch;
use EruoFood\Search\Domain\SavedSearch\SavedSearchRepository;
use EruoFood\Search\Domain\ValueObject\SearchFilters;
use EruoFood\Search\Infrastructure\Persistence\Eloquent\Model\SavedSearchModel;
use Illuminate\Support\Str;

final class EloquentSavedSearchRepository implements SavedSearchRepository
{
    public function nextIdentity(): string
    {
        return (string) Str::orderedUuid();
    }

    public function findById(string $id): ?SavedSearch
    {
        $m = SavedSearchModel::query()->find($id);

        return $m !== null ? $this->toDomain($m) : null;
    }

    public function forUser(string $userId): array
    {
        return array_map(
            fn (SavedSearchModel $m): SavedSearch => $this->toDomain($m),
            SavedSearchModel::query()->where('user_id', $userId)->orderByDesc('created_at')->get()->all(),
        );
    }

    public function save(SavedSearch $savedSearch): void
    {
        $model = SavedSearchModel::query()->find($savedSearch->id()) ?? new SavedSearchModel();
        $model->id = $savedSearch->id();
        $model->user_id = $savedSearch->userId();
        $model->name = $savedSearch->name();
        $model->term = $savedSearch->term();
        $model->type = $savedSearch->type()->value;
        $model->sort = $savedSearch->sort()->value;
        $model->filters = $savedSearch->filters()->toArray();
        $model->created_at = $savedSearch->createdAt();
        $model->save();
    }

    public function delete(string $id): void
    {
        SavedSearchModel::query()->whereKey($id)->delete();
    }

    private function toDomain(SavedSearchModel $m): SavedSearch
    {
        /** @var array<string, mixed> $filters */
        $filters = $m->filters ?? [];

        return SavedSearch::reconstitute(
            id: $m->id,
            userId: $m->user_id,
            name: $m->name,
            term: (string) $m->term,
            type: SearchType::from($m->type),
            filters: $this->filtersFromArray($filters),
            sort: SortOption::from($m->sort),
            createdAt: DateTimeImmutable::createFromInterface($m->created_at),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function filtersFromArray(array $data): SearchFilters
    {
        return new SearchFilters(
            state: isset($data['state']) ? (string) $data['state'] : null,
            region: isset($data['region']) ? (string) $data['region'] : null,
            cuisine: isset($data['cuisine']) ? (string) $data['cuisine'] : null,
            category: isset($data['category']) ? (string) $data['category'] : null,
            ingredients: $this->stringList($data['ingredients'] ?? []),
            maxCalories: isset($data['max_calories']) ? (int) $data['max_calories'] : null,
            minPriceMinor: isset($data['min_price']) ? (int) $data['min_price'] : null,
            maxPriceMinor: isset($data['max_price']) ? (int) $data['max_price'] : null,
            restaurantId: isset($data['restaurant_id']) ? (string) $data['restaurant_id'] : null,
            vendorId: isset($data['vendor_id']) ? (string) $data['vendor_id'] : null,
            minRating: isset($data['min_rating']) ? (float) $data['min_rating'] : null,
            maxCookingTime: isset($data['max_cooking_time']) ? (int) $data['max_cooking_time'] : null,
            difficulty: isset($data['difficulty']) ? (string) $data['difficulty'] : null,
            dietary: $this->stringList($data['dietary'] ?? []),
            excludeAllergens: $this->stringList($data['exclude_allergens'] ?? []),
        );
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_map('strval', $value)) : [];
    }
}
