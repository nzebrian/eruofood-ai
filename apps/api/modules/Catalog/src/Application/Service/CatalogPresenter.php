<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Application\Service;

use EruoFood\Catalog\Application\Port\ImageStorage;
use EruoFood\Catalog\Domain\Category\Category;
use EruoFood\Catalog\Domain\Food\Food;
use EruoFood\Catalog\Domain\Ingredient\Ingredient;
use EruoFood\Catalog\Domain\Recipe\Recipe;
use EruoFood\Catalog\Domain\Recipe\RecipeReview;
use EruoFood\Catalog\Domain\ValueObject\CookingStep;
use EruoFood\Catalog\Domain\ValueObject\LocalName;
use EruoFood\Catalog\Domain\ValueObject\RecipeIngredient;

/**
 * Maps Catalog aggregates to API-shaped arrays, resolving stored media paths to
 * URLs. Centralising presentation here keeps controllers and resources thin and
 * avoids leaking the storage port into the interface layer.
 */
final readonly class CatalogPresenter
{
    public function __construct(private ImageStorage $images)
    {
    }

    /** @return array<string, mixed> */
    public function category(Category $c): array
    {
        return [
            'id' => $c->id(),
            'name' => $c->name(),
            'slug' => (string) $c->slug(),
            'type' => $c->type()->value,
            'description' => $c->description(),
            'sort_order' => $c->sortOrder(),
            'active' => $c->isActive(),
        ];
    }

    /** @return array<string, mixed> */
    public function ingredient(Ingredient $i): array
    {
        return [
            'id' => $i->id(),
            'name' => $i->name(),
            'slug' => (string) $i->slug(),
            'description' => $i->description(),
            'local_names' => array_map(static fn (LocalName $l): array => $l->toArray(), $i->localNames()),
            'nutrition_per_100g' => $i->nutritionPer100g()?->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    public function foodSummary(Food $f): array
    {
        $images = array_map(fn (string $p): string => $this->images->url($p), $f->images());

        return [
            'id' => $f->id(),
            'name' => $f->name(),
            'slug' => (string) $f->slug(),
            'category_id' => $f->categoryId(),
            'region' => $f->region()->value,
            'tags' => $f->tags(),
            'status' => $f->status()->value,
            'primary_image' => $images[0] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    public function food(Food $f): array
    {
        return array_merge($this->foodSummary($f), [
            'description' => $f->description(),
            'states' => $f->states(),
            'local_names' => array_map(static fn (LocalName $l): array => $l->toArray(), $f->localNames()),
            'nutrition' => $f->nutrition()?->toArray(),
            'images' => array_map(fn (string $p): string => $this->images->url($p), $f->images()),
            'video_url' => $f->videoUrl(),
        ]);
    }

    /** @return array<string, mixed> */
    public function recipeSummary(Recipe $r): array
    {
        return [
            'id' => $r->id(),
            'food_id' => $r->foodId(),
            'title' => $r->title(),
            'slug' => (string) $r->slug(),
            'summary' => $r->summary(),
            'difficulty' => $r->difficulty()->value,
            'prep_time_minutes' => $r->prepTimeMinutes(),
            'cook_time_minutes' => $r->cookTimeMinutes(),
            'total_time_minutes' => $r->totalTimeMinutes(),
            'serving_size' => $r->servingSize(),
            'rating_average' => $r->ratingAverage(),
            'rating_count' => $r->ratingCount(),
            'tags' => $r->tags(),
            'status' => $r->status()->value,
        ];
    }

    /**
     * @param list<string> $favouritedIds
     * @return array<string, mixed>
     */
    public function recipe(Recipe $r, array $favouritedIds = []): array
    {
        return array_merge($this->recipeSummary($r), [
            'author_id' => $r->authorId(),
            'version' => $r->version(),
            'ingredients' => array_map(
                static fn (RecipeIngredient $i): array => $i->toArray(),
                $r->ingredients(),
            ),
            'steps' => array_map(fn (CookingStep $s): array => [
                'order' => $s->order,
                'instruction' => $s->instruction,
                'image_url' => $s->imagePath !== null ? $this->images->url($s->imagePath) : null,
                'duration_minutes' => $s->durationMinutes,
            ], $r->steps()),
            'tips' => $r->tips(),
            'related_recipe_ids' => $r->relatedRecipeIds(),
            'is_favourited' => in_array($r->id(), $favouritedIds, true),
        ]);
    }

    /** @return array<string, mixed> */
    public function review(RecipeReview $review): array
    {
        return [
            'id' => $review->id(),
            'recipe_id' => $review->recipeId(),
            'user_id' => $review->userId(),
            'rating' => $review->rating()->value,
            'comment' => $review->comment(),
            'created_at' => $review->createdAt()->format(DATE_ATOM),
        ];
    }
}
