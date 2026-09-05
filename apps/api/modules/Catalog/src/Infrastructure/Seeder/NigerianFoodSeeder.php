<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Infrastructure\Seeder;

use EruoFood\Catalog\Application\Input\FoodInput;
use EruoFood\Catalog\Application\Input\RecipeInput;
use EruoFood\Catalog\Application\Service\CategoryService;
use EruoFood\Catalog\Application\Service\FoodService;
use EruoFood\Catalog\Application\Service\RecipeService;
use EruoFood\Catalog\Domain\Category\CategoryRepository;
use EruoFood\Catalog\Domain\Enum\CategoryType;
use EruoFood\Catalog\Domain\Food\FoodRepository;
use EruoFood\Catalog\Domain\Recipe\RecipeRepository;
use EruoFood\Shared\Domain\ValueObject\Slug;
use Illuminate\Database\Seeder;

/**
 * Seeds a starter set of real Nigerian foods across regions, with categories,
 * local names, and nutrition — enough to demonstrate the catalogue end-to-end.
 * Run: php artisan db:seed --class="EruoFood\Catalog\Infrastructure\Seeder\NigerianFoodSeeder"
 */
final class NigerianFoodSeeder extends Seeder
{
    private const SYSTEM_AUTHOR = '00000000-0000-4000-8000-000000000001';

    private const JOLLOF_RECIPE_TITLE = 'Classic Nigerian Jollof Rice';

    public function run(): void
    {
        /** @var CategoryService $categories */
        $categories = $this->container->make(CategoryService::class);
        /** @var FoodService $foods */
        $foods = $this->container->make(FoodService::class);
        /** @var RecipeService $recipes */
        $recipes = $this->container->make(RecipeService::class);
        /** @var CategoryRepository $categoryRepo */
        $categoryRepo = $this->container->make(CategoryRepository::class);
        /** @var FoodRepository $foodRepo */
        $foodRepo = $this->container->make(FoodRepository::class);
        /** @var RecipeRepository $recipeRepo */
        $recipeRepo = $this->container->make(RecipeRepository::class);

        $cat = [
            'rice' => $this->ensureCategory($categories, $categoryRepo, 'Rice', CategoryType::Rice, 'Rice-based dishes', 1),
            'soup' => $this->ensureCategory($categories, $categoryRepo, 'Soups', CategoryType::Soup, 'Nigerian soups', 2),
            'swallow' => $this->ensureCategory($categories, $categoryRepo, 'Swallows', CategoryType::Swallow, 'Fufu, pounded yam, eba…', 3),
            'snack' => $this->ensureCategory($categories, $categoryRepo, 'Snacks', CategoryType::Snack, 'Snacks & small chops', 4),
            'street' => $this->ensureCategory($categories, $categoryRepo, 'Street Food', CategoryType::StreetFood, 'Roadside favourites', 5),
            'drink' => $this->ensureCategory($categories, $categoryRepo, 'Drinks', CategoryType::Drink, 'Local beverages', 6),
        ];

        $dishes = [
            ['Jollof Rice', $cat['rice'], 'south_west', ['Lagos', 'Oyo'], [['name' => 'Jollof', 'language' => 'pidgin']],
                ['calories' => 350, 'protein_grams' => 8, 'carbohydrate_grams' => 60, 'fat_grams' => 9], ['party', 'staple']],
            ['Egusi Soup', $cat['soup'], 'south_east', ['Anambra', 'Enugu'], [['name' => 'Ofe Egusi', 'language' => 'igbo']],
                ['calories' => 420, 'protein_grams' => 22, 'carbohydrate_grams' => 12, 'fat_grams' => 30], ['soup', 'melon']],
            ['Pounded Yam', $cat['swallow'], 'south_west', ['Ekiti', 'Osun'], [['name' => 'Iyan', 'language' => 'yoruba']],
                ['calories' => 300, 'protein_grams' => 4, 'carbohydrate_grams' => 70, 'fat_grams' => 1], ['swallow']],
            ['Suya', $cat['street'], 'north_west', ['Kano', 'Kaduna'], [['name' => 'Tsire', 'language' => 'hausa']],
                ['calories' => 280, 'protein_grams' => 26, 'carbohydrate_grams' => 5, 'fat_grams' => 18], ['grill', 'spicy']],
            ['Akara', $cat['snack'], 'south_west', ['Lagos'], [['name' => 'Akara', 'language' => 'yoruba']],
                ['calories' => 190, 'protein_grams' => 9, 'carbohydrate_grams' => 15, 'fat_grams' => 11], ['breakfast', 'beans']],
            ['Moi Moi', $cat['snack'], 'south_west', ['Lagos', 'Ogun'], [['name' => 'Moin Moin', 'language' => 'yoruba']],
                ['calories' => 210, 'protein_grams' => 12, 'carbohydrate_grams' => 18, 'fat_grams' => 9], ['beans', 'steamed']],
            ['Puff Puff', $cat['snack'], 'nationwide', [], [['name' => 'Bofrot', 'language' => 'pidgin']],
                ['calories' => 250, 'protein_grams' => 4, 'carbohydrate_grams' => 40, 'fat_grams' => 9], ['sweet', 'snack']],
            ['Zobo', $cat['drink'], 'north_central', ['Abuja'], [['name' => 'Zobo', 'language' => 'hausa']],
                ['calories' => 60, 'protein_grams' => 0, 'carbohydrate_grams' => 15, 'fat_grams' => 0], ['drink', 'hibiscus']],
        ];

        $jollofId = null;
        foreach ($dishes as [$name, $categoryId, $region, $states, $localNames, $nutrition, $tags]) {
            // `FoodService::create()` de-duplicates the slug by appending a
            // counter, so a second run would not fail here — it would quietly
            // grow a "jollof-rice-2". Skipping what is already seeded keeps the
            // starter catalogue the same set however many times this runs.
            $existing = $foodRepo->findBySlug(Slug::fromTitle($name)->value);
            if ($existing !== null) {
                if ($name === 'Jollof Rice') {
                    $jollofId = $existing->id();
                }

                continue;
            }

            $food = $foods->create(FoodInput::fromArray([
                'name' => $name,
                'category_id' => $categoryId,
                'region' => $region,
                'states' => $states,
                'local_names' => $localNames,
                'nutrition' => $nutrition,
                'tags' => $tags,
            ]));
            $foods->publish($food->id());
            if ($name === 'Jollof Rice') {
                $jollofId = $food->id();
            }
        }

        if ($jollofId !== null && ! $recipeRepo->existsBySlug(Slug::fromTitle(self::JOLLOF_RECIPE_TITLE)->value)) {
            $recipe = $recipes->create(self::SYSTEM_AUTHOR, RecipeInput::fromArray([
                'food_id' => $jollofId,
                'title' => self::JOLLOF_RECIPE_TITLE,
                'summary' => 'Smoky party jollof with the signature bottom-pot flavour.',
                'prep_time_minutes' => 20,
                'cook_time_minutes' => 45,
                'difficulty' => 'medium',
                'serving_size' => 6,
                'ingredients' => [
                    ['name' => 'Long-grain parboiled rice', 'amount' => 4, 'unit' => 'cup'],
                    ['name' => 'Blended tomatoes & pepper', 'amount' => 3, 'unit' => 'cup'],
                    ['name' => 'Vegetable oil', 'amount' => 0.5, 'unit' => 'cup'],
                    ['name' => 'Onions', 'amount' => 2, 'unit' => 'piece'],
                    ['name' => 'Salt', 'amount' => 1, 'unit' => 'to_taste'],
                ],
                'steps' => [
                    ['order' => 1, 'instruction' => 'Fry the blended tomato-pepper base in oil until reduced.', 'duration_minutes' => 15],
                    ['order' => 2, 'instruction' => 'Add stock and seasoning; bring to a boil.', 'duration_minutes' => 5],
                    ['order' => 3, 'instruction' => 'Add washed rice, cover, and cook on low heat until done.', 'duration_minutes' => 25],
                ],
                'tips' => ['Cook on low heat for the smoky "party" flavour.'],
                'tags' => ['party', 'rice'],
            ]));
            $recipes->publish($recipe->id());
        }
    }

    /**
     * Return the id of the category with this name's slug, creating it only if
     * it is not there yet.
     *
     * `catalog_categories.slug` is unique and `CategoryService::create()`
     * derives the slug straight from the name with no de-duplication, so the
     * previous unconditional create aborted the entire seed with
     * `catalog_categories_slug_unique` the second time it ran. Looking the slug
     * up first fixes that without touching the schema, without catching the
     * duplicate-key exception, and without overwriting a category somebody has
     * since edited.
     */
    private function ensureCategory(
        CategoryService $categories,
        CategoryRepository $repository,
        string $name,
        CategoryType $type,
        ?string $description,
        int $sortOrder,
    ): string {
        $existing = $repository->findBySlug(Slug::fromTitle($name)->value);

        return $existing?->id() ?? $categories->create($name, $type, $description, $sortOrder)->id();
    }
}
