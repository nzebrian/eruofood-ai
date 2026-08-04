<?php

declare(strict_types=1);

namespace EruoFood\Search\Domain\ValueObject;

/**
 * The full set of discovery filters. Every field is optional; an empty filter
 * set matches everything. Scalar fields are pushed down to SQL columns; the
 * list fields (ingredients / dietary / allergens / states) are matched against
 * the document's indexed facets during the re-rank stage.
 */
final readonly class SearchFilters
{
    /**
     * @param list<string> $ingredients must contain all of these
     * @param list<string> $dietary must satisfy all of these dietary preferences
     * @param list<string> $excludeAllergens must contain none of these allergens
     */
    public function __construct(
        public ?string $state = null,
        public ?string $region = null,
        public ?string $cuisine = null,
        public ?string $category = null,
        public array $ingredients = [],
        public ?int $maxCalories = null,
        public ?int $minPriceMinor = null,
        public ?int $maxPriceMinor = null,
        public ?string $restaurantId = null,
        public ?string $vendorId = null,
        public ?float $minRating = null,
        public ?int $maxCookingTime = null,
        public ?string $difficulty = null,
        public array $dietary = [],
        public array $excludeAllergens = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->toArray() === [];
    }

    /**
     * A stable, order-independent representation for cache keys and analytics.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];
        foreach ([
            'state' => $this->state,
            'region' => $this->region,
            'cuisine' => $this->cuisine,
            'category' => $this->category,
            'max_calories' => $this->maxCalories,
            'min_price' => $this->minPriceMinor,
            'max_price' => $this->maxPriceMinor,
            'restaurant_id' => $this->restaurantId,
            'vendor_id' => $this->vendorId,
            'min_rating' => $this->minRating,
            'max_cooking_time' => $this->maxCookingTime,
            'difficulty' => $this->difficulty,
        ] as $key => $value) {
            if ($value !== null) {
                $out[$key] = $value;
            }
        }
        foreach ([
            'ingredients' => $this->ingredients,
            'dietary' => $this->dietary,
            'exclude_allergens' => $this->excludeAllergens,
        ] as $key => $list) {
            if ($list !== []) {
                $values = array_map('strval', $list);
                sort($values);
                $out[$key] = $values;
            }
        }

        return $out;
    }
}
