<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Domain\Recipe;

use EruoFood\Catalog\Domain\Enum\ContentStatus;
use EruoFood\Catalog\Domain\Enum\Difficulty;
use EruoFood\Catalog\Domain\Event\RecipePublished;
use EruoFood\Catalog\Domain\ValueObject\CookingStep;
use EruoFood\Catalog\Domain\ValueObject\RecipeIngredient;
use EruoFood\Shared\Domain\AggregateRoot;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;
use EruoFood\Shared\Domain\ValueObject\Slug;

/**
 * Recipe aggregate root. Owns its ingredients, ordered cooking steps, tips,
 * tags, related recipes, publication state, version number, and denormalised
 * rating summary. Editing the content bumps the version (recipe versioning).
 */
final class Recipe extends AggregateRoot
{
    /**
     * @param list<RecipeIngredient> $ingredients
     * @param list<CookingStep> $steps
     * @param list<string> $tips
     * @param list<string> $tags
     * @param list<string> $relatedRecipeIds
     */
    private function __construct(
        private readonly string $id,
        private readonly string $foodId,
        private readonly string $authorId,
        private string $title,
        private Slug $slug,
        private ?string $summary,
        private int $prepTimeMinutes,
        private int $cookTimeMinutes,
        private Difficulty $difficulty,
        private int $servingSize,
        private array $ingredients,
        private array $steps,
        private array $tips,
        private array $tags,
        private array $relatedRecipeIds,
        private ContentStatus $status,
        private int $version,
        private float $ratingAverage,
        private int $ratingCount,
    ) {
        if ($servingSize < 1) {
            throw new InvalidArgumentException('Serving size must be at least 1.');
        }
    }

    /**
     * @param list<RecipeIngredient> $ingredients
     * @param list<CookingStep> $steps
     * @param list<string> $tips
     * @param list<string> $tags
     */
    public static function create(
        string $id,
        string $foodId,
        string $authorId,
        string $title,
        Slug $slug,
        int $prepTimeMinutes,
        int $cookTimeMinutes,
        Difficulty $difficulty,
        int $servingSize,
        array $ingredients,
        array $steps,
        ?string $summary = null,
        array $tips = [],
        array $tags = [],
    ): self {
        return new self(
            $id,
            $foodId,
            $authorId,
            $title,
            $slug,
            $summary,
            $prepTimeMinutes,
            $cookTimeMinutes,
            $difficulty,
            $servingSize,
            $ingredients,
            self::sortSteps($steps),
            $tips,
            $tags,
            [],
            ContentStatus::Draft,
            1,
            0.0,
            0,
        );
    }

    /**
     * @param list<RecipeIngredient> $ingredients
     * @param list<CookingStep> $steps
     * @param list<string> $tips
     * @param list<string> $tags
     * @param list<string> $relatedRecipeIds
     */
    public static function reconstitute(
        string $id,
        string $foodId,
        string $authorId,
        string $title,
        Slug $slug,
        ?string $summary,
        int $prepTimeMinutes,
        int $cookTimeMinutes,
        Difficulty $difficulty,
        int $servingSize,
        array $ingredients,
        array $steps,
        array $tips,
        array $tags,
        array $relatedRecipeIds,
        ContentStatus $status,
        int $version,
        float $ratingAverage,
        int $ratingCount,
    ): self {
        return new self(
            $id,
            $foodId,
            $authorId,
            $title,
            $slug,
            $summary,
            $prepTimeMinutes,
            $cookTimeMinutes,
            $difficulty,
            $servingSize,
            $ingredients,
            $steps,
            $tips,
            $tags,
            $relatedRecipeIds,
            $status,
            $version,
            $ratingAverage,
            $ratingCount,
        );
    }

    /**
     * Edit the recipe content and bump the version number.
     *
     * @param list<RecipeIngredient> $ingredients
     * @param list<CookingStep> $steps
     * @param list<string> $tips
     * @param list<string> $tags
     */
    public function updateContent(
        string $title,
        Slug $slug,
        ?string $summary,
        int $prepTimeMinutes,
        int $cookTimeMinutes,
        Difficulty $difficulty,
        int $servingSize,
        array $ingredients,
        array $steps,
        array $tips,
        array $tags,
    ): void {
        if ($servingSize < 1) {
            throw new InvalidArgumentException('Serving size must be at least 1.');
        }
        $this->title = $title;
        $this->slug = $slug;
        $this->summary = $summary;
        $this->prepTimeMinutes = $prepTimeMinutes;
        $this->cookTimeMinutes = $cookTimeMinutes;
        $this->difficulty = $difficulty;
        $this->servingSize = $servingSize;
        $this->ingredients = $ingredients;
        $this->steps = self::sortSteps($steps);
        $this->tips = $tips;
        $this->tags = $tags;
        $this->version++;
    }

    /** @param list<string> $ids */
    public function setRelatedRecipes(array $ids): void
    {
        $this->relatedRecipeIds = array_values(array_filter($ids, fn (string $id): bool => $id !== $this->id));
    }

    /** Recompute the denormalised rating summary from the review aggregate. */
    public function applyRatingSummary(float $average, int $count): void
    {
        $this->ratingAverage = round($average, 2);
        $this->ratingCount = $count;
    }

    public function publish(): void
    {
        if ($this->status === ContentStatus::Published) {
            return;
        }
        $this->status = ContentStatus::Published;
        $this->recordThat(new RecipePublished($this->id));
    }

    public function archive(): void
    {
        $this->status = ContentStatus::Archived;
    }

    public function isOwnedBy(string $userId): bool
    {
        return $this->authorId === $userId;
    }

    public function totalTimeMinutes(): int
    {
        return $this->prepTimeMinutes + $this->cookTimeMinutes;
    }

    /**
     * @param list<CookingStep> $steps
     * @return list<CookingStep>
     */
    private static function sortSteps(array $steps): array
    {
        usort($steps, static fn (CookingStep $a, CookingStep $b): int => $a->order <=> $b->order);

        return array_values($steps);
    }

    // ---- Accessors ----------------------------------------------------------

    public function id(): string
    {
        return $this->id;
    }

    public function foodId(): string
    {
        return $this->foodId;
    }

    public function authorId(): string
    {
        return $this->authorId;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function slug(): Slug
    {
        return $this->slug;
    }

    public function summary(): ?string
    {
        return $this->summary;
    }

    public function prepTimeMinutes(): int
    {
        return $this->prepTimeMinutes;
    }

    public function cookTimeMinutes(): int
    {
        return $this->cookTimeMinutes;
    }

    public function difficulty(): Difficulty
    {
        return $this->difficulty;
    }

    public function servingSize(): int
    {
        return $this->servingSize;
    }

    /** @return list<RecipeIngredient> */
    public function ingredients(): array
    {
        return $this->ingredients;
    }

    /** @return list<CookingStep> */
    public function steps(): array
    {
        return $this->steps;
    }

    /** @return list<string> */
    public function tips(): array
    {
        return $this->tips;
    }

    /** @return list<string> */
    public function tags(): array
    {
        return $this->tags;
    }

    /** @return list<string> */
    public function relatedRecipeIds(): array
    {
        return $this->relatedRecipeIds;
    }

    public function status(): ContentStatus
    {
        return $this->status;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function ratingAverage(): float
    {
        return $this->ratingAverage;
    }

    public function ratingCount(): int
    {
        return $this->ratingCount;
    }
}
