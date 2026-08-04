<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Domain\Item;

use EruoFood\Nutrition\Domain\ValueObject\NutritionFacts;
use EruoFood\Nutrition\Domain\ValueObject\ServingSize;
use EruoFood\Shared\Domain\ValueObject\Slug;

/**
 * An entry in the nutrition database: a food (or ingredient) with a reference
 * serving size and its full nutrition panel per serving. This is the module's
 * *own* nutrition data — meal analysis, diary totals and recipe breakdowns are
 * all computed by scaling and summing these panels, so Nutrition never joins
 * across to the Catalog's tables (it may hold an optional soft `foodId` link).
 */
final class NutritionItem
{
    private function __construct(
        private readonly string $id,
        private string $name,
        private Slug $slug,
        private string $category,
        private ServingSize $servingSize,
        private NutritionFacts $facts,
        private ?string $foodId,
    ) {
    }

    public static function create(
        string $id,
        string $name,
        Slug $slug,
        string $category,
        ServingSize $servingSize,
        NutritionFacts $facts,
        ?string $foodId = null,
    ): self {
        return new self($id, $name, $slug, $category, $servingSize, $facts, $foodId);
    }

    public function update(
        string $name,
        string $category,
        ServingSize $servingSize,
        NutritionFacts $facts,
        ?string $foodId,
    ): void {
        $this->name = $name;
        $this->category = $category;
        $this->servingSize = $servingSize;
        $this->facts = $facts;
        $this->foodId = $foodId;
    }

    /** The nutrition panel scaled to a number of reference servings. */
    public function factsForServings(float $servings): NutritionFacts
    {
        return $this->facts->scale($servings);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): Slug
    {
        return $this->slug;
    }

    public function category(): string
    {
        return $this->category;
    }

    public function servingSize(): ServingSize
    {
        return $this->servingSize;
    }

    public function facts(): NutritionFacts
    {
        return $this->facts;
    }

    public function foodId(): ?string
    {
        return $this->foodId;
    }
}
