<?php

declare(strict_types=1);

namespace EruoFood\Search\Interface\Http\Concerns;

use EruoFood\Search\Domain\Enum\SearchType;
use EruoFood\Search\Domain\Enum\SortOption;
use EruoFood\Search\Domain\ValueObject\GeoPoint;
use EruoFood\Search\Domain\ValueObject\SearchFilters;
use Illuminate\Http\Request;

/** Turns raw request query parameters into the search value objects. */
trait ParsesSearchParams
{
    protected function searchType(Request $request, SearchType $default = SearchType::Global): SearchType
    {
        $type = $request->query('type');

        return is_string($type) ? (SearchType::tryFrom($type) ?? $default) : $default;
    }

    protected function sortOption(Request $request): SortOption
    {
        $sort = $request->query('sort');

        return is_string($sort) ? (SortOption::tryFrom($sort) ?? SortOption::Relevance) : SortOption::Relevance;
    }

    protected function geoPoint(Request $request): ?GeoPoint
    {
        $lat = $request->query('lat');
        $lng = $request->query('lng');
        if (is_numeric($lat) && is_numeric($lng)) {
            return new GeoPoint((float) $lat, (float) $lng);
        }

        return null;
    }

    protected function filters(Request $request): SearchFilters
    {
        return new SearchFilters(
            state: $this->str($request->query('state')),
            region: $this->str($request->query('region')),
            cuisine: $this->str($request->query('cuisine')),
            category: $this->str($request->query('category')),
            ingredients: $this->list($request->query('ingredients')),
            maxCalories: $this->int($request->query('max_calories')),
            minPriceMinor: $this->int($request->query('min_price')),
            maxPriceMinor: $this->int($request->query('max_price')),
            restaurantId: $this->str($request->query('restaurant_id')),
            vendorId: $this->str($request->query('vendor_id')),
            minRating: $this->float($request->query('min_rating')),
            maxCookingTime: $this->int($request->query('max_cooking_time')),
            difficulty: $this->str($request->query('difficulty')),
            dietary: $this->list($request->query('dietary')),
            excludeAllergens: $this->list($request->query('exclude_allergens')),
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
     * Accept either an array or a comma-separated string.
     *
     * @return list<string>
     */
    private function list(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value), static fn (string $v): bool => $v !== ''));
        }
        if (is_string($value) && $value !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== ''));
        }

        return [];
    }
}
