<?php

declare(strict_types=1);

namespace EruoFood\PublicApi\Infrastructure\Read;

use EruoFood\Catalog\Domain\Food\Food;
use EruoFood\Catalog\Domain\Food\FoodRepository;
use EruoFood\Catalog\Domain\Food\FoodSearchCriteria;
use EruoFood\Catalog\Domain\Recipe\Recipe;
use EruoFood\Catalog\Domain\Recipe\RecipeRepository;
use EruoFood\Catalog\Domain\Recipe\RecipeSearchCriteria;
use EruoFood\PublicApi\Domain\Read\CatalogReadPort;
use EruoFood\PublicApi\Domain\Read\FoodResource;
use EruoFood\PublicApi\Domain\Read\RecipeResource;
use EruoFood\PublicApi\Domain\Read\ResourceQuery;
use EruoFood\Shared\Domain\Paginated;

/**
 * Adapts the Public API's {@see CatalogReadPort} onto the Catalog context's read
 * side. This is the one sanctioned cross-context seam for the API façade: it
 * consumes Catalog's published read repositories (never its write model or
 * tables) and maps Catalog entities to the Public API's own resource DTOs, so
 * the external contract stays independent of Catalog's internal representation.
 * Only published content is ever exposed (the criteria default).
 */
final readonly class CatalogReadAdapter implements CatalogReadPort
{
    public function __construct(
        private FoodRepository $foods,
        private RecipeRepository $recipes,
    ) {
    }

    public function foods(ResourceQuery $query): Paginated
    {
        $criteria = new FoodSearchCriteria(term: $query->search);
        $page = $this->foods->search($criteria, $query->page, $query->perPage);

        return new Paginated(
            array_map(fn (Food $f): FoodResource => $this->toFood($f), $page->items),
            $page->total,
            $page->page,
            $page->perPage,
        );
    }

    public function food(string $slug): ?FoodResource
    {
        $food = $this->foods->findBySlug($slug);

        return $food !== null ? $this->toFood($food) : null;
    }

    public function recipes(ResourceQuery $query): Paginated
    {
        $criteria = new RecipeSearchCriteria(term: $query->search);
        $page = $this->recipes->search($criteria, $query->page, $query->perPage);

        return new Paginated(
            array_map(fn (Recipe $r): RecipeResource => $this->toRecipe($r), $page->items),
            $page->total,
            $page->page,
            $page->perPage,
        );
    }

    public function recipe(string $slug): ?RecipeResource
    {
        $recipe = $this->recipes->findBySlug($slug);

        return $recipe !== null ? $this->toRecipe($recipe) : null;
    }

    private function toFood(Food $f): FoodResource
    {
        $images = $f->images();
        $first = $images[0] ?? null;

        return new FoodResource(
            $f->id(),
            (string) $f->slug(),
            $f->name(),
            $f->description(),
            $f->region()->value,
            is_scalar($first) ? (string) $first : null,
            array_values(array_map('strval', $f->tags())),
            null,
        );
    }

    private function toRecipe(Recipe $r): RecipeResource
    {
        return new RecipeResource(
            $r->id(),
            (string) $r->slug(),
            $r->title(),
            $r->summary(),
            $r->prepTimeMinutes(),
            $r->cookTimeMinutes(),
            $r->difficulty()->value,
            null,
        );
    }
}
