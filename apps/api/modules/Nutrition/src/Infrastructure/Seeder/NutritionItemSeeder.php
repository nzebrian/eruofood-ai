<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Infrastructure\Seeder;

use EruoFood\Nutrition\Application\Input\NutritionItemInput;
use EruoFood\Nutrition\Application\Service\NutritionItemService;
use EruoFood\Nutrition\Domain\Item\NutritionItemRepository;
use EruoFood\Shared\Domain\ValueObject\Slug;
use Illuminate\Database\Seeder;

/**
 * Seeds a starter nutrition database of common Nigerian foods (approximate
 * per-serving values). Idempotent — items whose slug already exists are skipped —
 * so it is safe to re-run. Run:
 *   php artisan db:seed --class="EruoFood\Nutrition\Infrastructure\Seeder\NutritionItemSeeder"
 */
final class NutritionItemSeeder extends Seeder
{
    public function __construct(
        private readonly NutritionItemService $items,
        private readonly NutritionItemRepository $repository,
    ) {
    }

    public function run(): void
    {
        foreach ($this->definitions() as $definition) {
            if ($this->repository->slugExists((string) Slug::fromTitle($definition['name']))) {
                continue;
            }
            $this->items->create(NutritionItemInput::fromArray($definition));
        }
    }

    /** @return list<array<string, mixed>> */
    private function definitions(): array
    {
        return [
            $this->item('Jollof Rice', 'rice', '1 plate', 250, 330, 7, 55, 9, 2, 4, 600, 0, 120),
            $this->item('Pounded Yam', 'swallow', '1 wrap', 200, 300, 3, 70, 0.5, 4, 1, 20, 0, 100),
            $this->item('Egusi Soup', 'soup', '1 bowl', 200, 350, 18, 10, 26, 3, 2, 700, 40, 120, ['iron_mg' => 4.5]),
            $this->item('Efo Riro', 'soup', '1 bowl', 200, 220, 14, 8, 15, 4, 2, 650, 30, 130, ['vitamin_c_mg' => 30, 'iron_mg' => 3.8]),
            $this->item('Moi Moi', 'protein', '1 piece', 150, 230, 12, 22, 10, 5, 2, 350, 0, 90),
            $this->item('Suya (Beef)', 'protein', '1 stick', 100, 250, 26, 3, 15, 0, 1, 500, 70, 45),
            $this->item('Fried Plantain (Dodo)', 'side_dish', '1 serving', 120, 220, 1.5, 38, 8, 2.5, 16, 5, 0, 40),
            $this->item('Beans (Ewa)', 'protein', '1 bowl', 200, 240, 15, 40, 1, 11, 3, 300, 0, 110, ['iron_mg' => 5.0]),
            $this->item('Akara', 'snack', '3 balls', 100, 220, 8, 18, 13, 4, 2, 250, 0, 30),
            $this->item('Catfish Pepper Soup', 'soup', '1 bowl', 250, 180, 22, 4, 8, 1, 1, 600, 60, 170),
        ];
    }

    /**
     * @param array<string, float> $micros
     * @return array<string, mixed>
     */
    private function item(
        string $name,
        string $category,
        string $servingLabel,
        float $grams,
        float $calories,
        float $protein,
        float $carbs,
        float $fat,
        float $fibre,
        float $sugar,
        float $sodium,
        float $cholesterol,
        float $water,
        array $micros = [],
    ): array {
        return [
            'name' => $name,
            'category' => $category,
            'serving_size' => ['label' => $servingLabel, 'grams' => $grams],
            'nutrition' => [
                'calories' => $calories,
                'protein_grams' => $protein,
                'carb_grams' => $carbs,
                'fat_grams' => $fat,
                'fibre_grams' => $fibre,
                'sugar_grams' => $sugar,
                'sodium_mg' => $sodium,
                'cholesterol_mg' => $cholesterol,
                'water_ml' => $water,
                'micronutrients' => $micros,
            ],
        ];
    }
}
