<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Application\Service;

use EruoFood\Catalog\Application\Input\RecipeInput;
use EruoFood\Catalog\Domain\Exception\CatalogNotFound;
use EruoFood\Catalog\Domain\Exception\NotRecipeAuthor;
use EruoFood\Catalog\Domain\Food\FoodRepository;
use EruoFood\Catalog\Domain\Recipe\Recipe;
use EruoFood\Catalog\Domain\Recipe\RecipeRepository;
use EruoFood\Catalog\Domain\Recipe\RecipeSearchCriteria;
use EruoFood\Catalog\Domain\Recipe\RecipeVersionRepository;
use EruoFood\Catalog\Domain\ValueObject\CookingStep;
use EruoFood\Catalog\Domain\ValueObject\RecipeIngredient;
use EruoFood\Shared\Domain\Paginated;
use EruoFood\Shared\Domain\ValueObject\Slug;

/** Recipe use cases: browse/search, CRUD (with versioning), publish, relate. */
final readonly class RecipeService
{
    public function __construct(
        private RecipeRepository $recipes,
        private FoodRepository $foods,
        private RecipeVersionRepository $versions,
    ) {
    }

    /**
     * @return Paginated<Recipe>
     */
    public function search(RecipeSearchCriteria $criteria, int $page, int $perPage): Paginated
    {
        return $this->recipes->search($criteria, max(1, $page), min(60, max(1, $perPage)));
    }

    public function getBySlug(string $slug): Recipe
    {
        return $this->recipes->findBySlug($slug) ?? throw CatalogNotFound::of('recipe', $slug);
    }

    public function getById(string $id): Recipe
    {
        return $this->load($id);
    }

    public function create(string $authorId, RecipeInput $input): Recipe
    {
        // Ensure the recipe attaches to a real food.
        if ($this->foods->findById($input->foodId) === null) {
            throw CatalogNotFound::of('food', $input->foodId);
        }

        $recipe = Recipe::create(
            id: $this->recipes->nextIdentity(),
            foodId: $input->foodId,
            authorId: $authorId,
            title: $input->title,
            slug: $this->uniqueSlug($input->title),
            prepTimeMinutes: $input->prepTimeMinutes,
            cookTimeMinutes: $input->cookTimeMinutes,
            difficulty: $input->difficulty,
            servingSize: $input->servingSize,
            ingredients: $input->ingredients,
            steps: $input->steps,
            summary: $input->summary,
            tips: $input->tips,
            tags: $input->tags,
        );

        $this->recipes->save($recipe);
        $this->versions->record($recipe->id(), $recipe->version(), $this->snapshot($recipe));

        return $recipe;
    }

    public function update(string $actorId, bool $actorIsAdmin, string $id, RecipeInput $input): Recipe
    {
        $recipe = $this->load($id);
        $this->assertCanModify($recipe, $actorId, $actorIsAdmin);

        $slug = $recipe->title() === $input->title ? $recipe->slug() : $this->uniqueSlug($input->title, $id);

        $recipe->updateContent(
            title: $input->title,
            slug: $slug,
            summary: $input->summary,
            prepTimeMinutes: $input->prepTimeMinutes,
            cookTimeMinutes: $input->cookTimeMinutes,
            difficulty: $input->difficulty,
            servingSize: $input->servingSize,
            ingredients: $input->ingredients,
            steps: $input->steps,
            tips: $input->tips,
            tags: $input->tags,
        );

        $this->recipes->save($recipe);
        // Snapshot the new version for history (recipe versioning).
        $this->versions->record($recipe->id(), $recipe->version(), $this->snapshot($recipe));

        return $recipe;
    }

    public function publish(string $id): Recipe
    {
        $recipe = $this->load($id);
        $recipe->publish();
        $this->recipes->save($recipe);

        return $recipe;
    }

    public function archive(string $id): Recipe
    {
        $recipe = $this->load($id);
        $recipe->archive();
        $this->recipes->save($recipe);

        return $recipe;
    }

    public function delete(string $actorId, bool $actorIsAdmin, string $id): void
    {
        $recipe = $this->load($id);
        $this->assertCanModify($recipe, $actorId, $actorIsAdmin);
        $this->recipes->delete($id);
    }

    /** @param list<string> $relatedIds */
    public function setRelated(string $id, array $relatedIds): Recipe
    {
        $recipe = $this->load($id);
        $recipe->setRelatedRecipes($relatedIds);
        $this->recipes->save($recipe);

        return $recipe;
    }

    /** @return list<Recipe> */
    public function related(string $id): array
    {
        $recipe = $this->load($id);

        return $this->recipes->findManyByIds($recipe->relatedRecipeIds());
    }

    /**
     * @return list<array{version: int, snapshot: array<string, mixed>, created_at: string}>
     */
    public function versionHistory(string $id): array
    {
        $this->load($id);

        return $this->versions->history($id);
    }

    private function assertCanModify(Recipe $recipe, string $actorId, bool $actorIsAdmin): void
    {
        if (! $actorIsAdmin && ! $recipe->isOwnedBy($actorId)) {
            throw new NotRecipeAuthor();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Recipe $recipe): array
    {
        return [
            'title' => $recipe->title(),
            'summary' => $recipe->summary(),
            'prep_time_minutes' => $recipe->prepTimeMinutes(),
            'cook_time_minutes' => $recipe->cookTimeMinutes(),
            'difficulty' => $recipe->difficulty()->value,
            'serving_size' => $recipe->servingSize(),
            'ingredients' => array_map(static fn (RecipeIngredient $i): array => $i->toArray(), $recipe->ingredients()),
            'steps' => array_map(static fn (CookingStep $s): array => $s->toArray(), $recipe->steps()),
            'tips' => $recipe->tips(),
            'tags' => $recipe->tags(),
        ];
    }

    private function uniqueSlug(string $title, ?string $ignoreId = null): Slug
    {
        $base = Slug::fromTitle($title);
        $candidate = $base;
        $suffix = 1;

        while ($this->recipes->existsBySlug($candidate->value)) {
            $existing = $this->recipes->findBySlug($candidate->value);
            if ($ignoreId !== null && $existing !== null && $existing->id() === $ignoreId) {
                break;
            }
            $suffix++;
            $candidate = new Slug($base->value.'-'.$suffix);
        }

        return $candidate;
    }

    private function load(string $id): Recipe
    {
        return $this->recipes->findById($id) ?? throw CatalogNotFound::of('recipe', $id);
    }
}
