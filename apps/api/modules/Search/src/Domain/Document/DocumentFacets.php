<?php

declare(strict_types=1);

namespace EruoFood\Search\Domain\Document;

use EruoFood\Search\Domain\ValueObject\SearchFilters;

/**
 * The filterable/sortable attributes of an indexed document. Centralising the
 * filter-matching rule here (rather than in SQL) keeps it DB-agnostic and unit
 * testable, and lets the portable (non-pgvector) search path re-rank a bounded
 * candidate pool in PHP with identical semantics to the pushed-down SQL.
 */
final readonly class DocumentFacets
{
    /**
     * @param list<string> $states
     * @param list<string> $ingredients
     * @param list<string> $dietary
     * @param list<string> $allergens
     */
    public function __construct(
        public ?string $region = null,
        public array $states = [],
        public ?string $cuisine = null,
        public ?string $category = null,
        public array $ingredients = [],
        public array $dietary = [],
        public array $allergens = [],
        public ?string $difficulty = null,
        public int $popularity = 0,
        public float $rating = 0.0,
        public ?int $priceMinor = null,
        public ?int $prepTimeMinutes = null,
        public ?int $calories = null,
        public ?string $restaurantId = null,
        public ?string $vendorId = null,
    ) {
    }

    /** Whether this document satisfies every constraint in the filter set. */
    public function matches(SearchFilters $f): bool
    {
        if ($f->region !== null && ! $this->equalsCi($this->region, $f->region)) {
            return false;
        }
        if ($f->cuisine !== null && ! $this->equalsCi($this->cuisine, $f->cuisine)) {
            return false;
        }
        if ($f->category !== null && ! $this->equalsCi($this->category, $f->category)) {
            return false;
        }
        if ($f->difficulty !== null && ! $this->equalsCi($this->difficulty, $f->difficulty)) {
            return false;
        }
        if ($f->restaurantId !== null && $this->restaurantId !== $f->restaurantId) {
            return false;
        }
        if ($f->vendorId !== null && $this->vendorId !== $f->vendorId) {
            return false;
        }
        if ($f->state !== null && ! $this->containsCi($this->states, $f->state)) {
            return false;
        }
        if ($f->minRating !== null && $this->rating < $f->minRating) {
            return false;
        }
        if ($f->maxCalories !== null && $this->calories !== null && $this->calories > $f->maxCalories) {
            return false;
        }
        if ($f->maxCookingTime !== null && $this->prepTimeMinutes !== null && $this->prepTimeMinutes > $f->maxCookingTime) {
            return false;
        }
        if ($f->minPriceMinor !== null && ($this->priceMinor === null || $this->priceMinor < $f->minPriceMinor)) {
            return false;
        }
        if ($f->maxPriceMinor !== null && ($this->priceMinor === null || $this->priceMinor > $f->maxPriceMinor)) {
            return false;
        }
        foreach ($f->ingredients as $needle) {
            if (! $this->containsCi($this->ingredients, $needle)) {
                return false;
            }
        }
        foreach ($f->dietary as $needle) {
            if (! $this->containsCi($this->dietary, $needle)) {
                return false;
            }
        }
        foreach ($f->excludeAllergens as $needle) {
            if ($this->containsCi($this->allergens, $needle)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $haystack
     */
    private function containsCi(array $haystack, string $needle): bool
    {
        $needle = mb_strtolower($needle);
        foreach ($haystack as $value) {
            if (mb_strtolower($value) === $needle) {
                return true;
            }
        }

        return false;
    }

    private function equalsCi(?string $a, string $b): bool
    {
        return $a !== null && mb_strtolower($a) === mb_strtolower($b);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'region' => $this->region,
            'states' => $this->states,
            'cuisine' => $this->cuisine,
            'category' => $this->category,
            'ingredients' => $this->ingredients,
            'dietary' => $this->dietary,
            'allergens' => $this->allergens,
            'difficulty' => $this->difficulty,
            'popularity' => $this->popularity,
            'rating' => $this->rating,
            'price_minor' => $this->priceMinor,
            'prep_time_minutes' => $this->prepTimeMinutes,
            'calories' => $this->calories,
            'restaurant_id' => $this->restaurantId,
            'vendor_id' => $this->vendorId,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            region: isset($data['region']) ? (string) $data['region'] : null,
            states: self::stringList($data['states'] ?? []),
            cuisine: isset($data['cuisine']) ? (string) $data['cuisine'] : null,
            category: isset($data['category']) ? (string) $data['category'] : null,
            ingredients: self::stringList($data['ingredients'] ?? []),
            dietary: self::stringList($data['dietary'] ?? []),
            allergens: self::stringList($data['allergens'] ?? []),
            difficulty: isset($data['difficulty']) ? (string) $data['difficulty'] : null,
            popularity: (int) ($data['popularity'] ?? 0),
            rating: (float) ($data['rating'] ?? 0.0),
            priceMinor: isset($data['price_minor']) ? (int) $data['price_minor'] : null,
            prepTimeMinutes: isset($data['prep_time_minutes']) ? (int) $data['prep_time_minutes'] : null,
            calories: isset($data['calories']) ? (int) $data['calories'] : null,
            restaurantId: isset($data['restaurant_id']) ? (string) $data['restaurant_id'] : null,
            vendorId: isset($data['vendor_id']) ? (string) $data['vendor_id'] : null,
        );
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map('strval', $value));
    }
}
