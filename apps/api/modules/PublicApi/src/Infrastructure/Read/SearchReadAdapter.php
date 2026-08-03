<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Infrastructure\Read;

use EruoFood\PublicApi\Domain\Read\SearchReadPort;
use EruoFood\Search\Application\Service\AutocompleteService;
use EruoFood\Search\Application\Service\QueryBuilder;
use EruoFood\Search\Application\Service\SearchPresenter;
use EruoFood\Search\Application\Service\SearchService;
use EruoFood\Search\Domain\Enum\SearchType;
use EruoFood\Search\Domain\Enum\SortOption;
use EruoFood\Search\Domain\ValueObject\SearchFilters;

/**
 * Adapts the Public API's {@see SearchReadPort} onto the Search context's
 * application services (query builder → pipeline → presenter, plus
 * autocomplete). This is a sanctioned cross-context read seam that reuses
 * Search's own published services rather than its index tables.
 *
 * The public search surface is never administrative: the query always runs with
 * `isAdmin=false` and no user id, and the admin-only {@see SearchType::User}
 * scope is refused (it falls back to a global search), so no private or
 * user-directory data can leak through the façade.
 */
final readonly class SearchReadAdapter implements SearchReadPort
{
    public function __construct(
        private QueryBuilder $queryBuilder,
        private SearchService $search,
        private AutocompleteService $autocomplete,
        private SearchPresenter $presenter,
    ) {
    }

    public function query(string $term, ?string $type, array $filters, int $page, int $perPage): array
    {
        $query = $this->queryBuilder->build(
            term: $term,
            type: $this->publicType($type),
            filters: $this->buildFilters($filters),
            sort: SortOption::tryFrom((string) ($filters['sort'] ?? '')) ?? SortOption::Relevance,
            page: $page,
            perPage: $perPage,
            locale: (string) ($filters['locale'] ?? 'en'),
            geo: null,
        );

        // Public surface: never admin, never a user id (public content only).
        $executed = $this->search->search($query, isAdmin: false, userId: null);

        return $this->presenter->results($executed->results, $executed->queryId);
    }

    public function suggestions(string $prefix, ?string $type): array
    {
        return $this->autocomplete->suggestions($prefix, $this->publicType($type));
    }

    public function filters(): array
    {
        return [
            ['key' => 'region', 'type' => 'string', 'description' => 'Filter by region.'],
            ['key' => 'state', 'type' => 'string', 'description' => 'Filter by state.'],
            ['key' => 'cuisine', 'type' => 'string', 'description' => 'Filter by cuisine.'],
            ['key' => 'category', 'type' => 'string', 'description' => 'Filter by category.'],
            ['key' => 'dietary', 'type' => 'list', 'description' => 'Dietary preferences the result must satisfy.'],
            ['key' => 'exclude_allergens', 'type' => 'list', 'description' => 'Allergens to exclude.'],
            ['key' => 'ingredients', 'type' => 'list', 'description' => 'Ingredients the result must contain.'],
            ['key' => 'max_calories', 'type' => 'int', 'description' => 'Maximum calories.'],
            ['key' => 'min_price', 'type' => 'int', 'description' => 'Minimum price (minor units).'],
            ['key' => 'max_price', 'type' => 'int', 'description' => 'Maximum price (minor units).'],
            ['key' => 'min_rating', 'type' => 'float', 'description' => 'Minimum average rating.'],
            ['key' => 'max_cooking_time', 'type' => 'int', 'description' => 'Maximum cooking time (minutes).'],
            ['key' => 'difficulty', 'type' => 'string', 'description' => 'Recipe difficulty.'],
            [
                'key' => 'type',
                'type' => 'enum',
                'description' => 'Restrict to a document type.',
                'values' => ['global', 'recipe', 'food', 'ingredient', 'restaurant', 'vendor', 'product', 'category'],
            ],
            [
                'key' => 'sort',
                'type' => 'enum',
                'description' => 'Result ordering.',
                'values' => ['relevance', 'popularity', 'rating', 'newest', 'price', 'prep_time'],
            ],
        ];
    }

    /** Resolve the requested type, refusing the admin-only User scope. */
    private function publicType(?string $type): SearchType
    {
        $resolved = $type !== null ? SearchType::tryFrom($type) : null;
        if ($resolved === null || $resolved->isAdminOnly()) {
            return SearchType::Global;
        }

        return $resolved;
    }

    /**
     * @param array<string, string> $filters
     */
    private function buildFilters(array $filters): SearchFilters
    {
        return new SearchFilters(
            state: $this->str($filters['state'] ?? null),
            region: $this->str($filters['region'] ?? null),
            cuisine: $this->str($filters['cuisine'] ?? null),
            category: $this->str($filters['category'] ?? null),
            ingredients: $this->list($filters['ingredients'] ?? null),
            maxCalories: $this->int($filters['max_calories'] ?? null),
            minPriceMinor: $this->int($filters['min_price'] ?? null),
            maxPriceMinor: $this->int($filters['max_price'] ?? null),
            restaurantId: $this->str($filters['restaurant_id'] ?? null),
            vendorId: $this->str($filters['vendor_id'] ?? null),
            minRating: $this->float($filters['min_rating'] ?? null),
            maxCookingTime: $this->int($filters['max_cooking_time'] ?? null),
            difficulty: $this->str($filters['difficulty'] ?? null),
            dietary: $this->list($filters['dietary'] ?? null),
            excludeAllergens: $this->list($filters['exclude_allergens'] ?? null),
        );
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function float(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return list<string>
     */
    private function list(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== ''));
        }

        return [];
    }
}
