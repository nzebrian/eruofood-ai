<?php

declare(strict_types=1);

namespace EruoFood\Nutrition\Domain\ValueObject;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/**
 * A complete nutrition panel for one serving: energy, the three macronutrients,
 * fibre/sugar/sodium/cholesterol/water, and an open map of micronutrients
 * (vitamins & minerals, in their conventional units).
 *
 * Immutable and composable — {@see scale()} adjusts for portion size and
 * {@see add()} sums panels, which is all meal/day/recipe aggregation needs. All
 * quantities are non-negative.
 */
final readonly class NutritionFacts
{
    /**
     * @param array<string, float> $micronutrients e.g. ['vitamin_c_mg' => 12.0, 'iron_mg' => 2.5]
     */
    public function __construct(
        public float $calories,
        public float $proteinGrams,
        public float $carbGrams,
        public float $fatGrams,
        public float $fibreGrams = 0.0,
        public float $sugarGrams = 0.0,
        public float $sodiumMg = 0.0,
        public float $cholesterolMg = 0.0,
        public float $waterMl = 0.0,
        public array $micronutrients = [],
    ) {
        foreach ([$calories, $proteinGrams, $carbGrams, $fatGrams, $fibreGrams, $sugarGrams, $sodiumMg, $cholesterolMg, $waterMl] as $value) {
            if ($value < 0) {
                throw new InvalidArgumentException('Nutrition values cannot be negative.');
            }
        }
    }

    public static function empty(): self
    {
        return new self(0, 0, 0, 0);
    }

    /** Scale every quantity by a factor (e.g. number of servings). */
    public function scale(float $factor): self
    {
        if ($factor < 0) {
            throw new InvalidArgumentException('Scale factor cannot be negative.');
        }

        return new self(
            $this->calories * $factor,
            $this->proteinGrams * $factor,
            $this->carbGrams * $factor,
            $this->fatGrams * $factor,
            $this->fibreGrams * $factor,
            $this->sugarGrams * $factor,
            $this->sodiumMg * $factor,
            $this->cholesterolMg * $factor,
            $this->waterMl * $factor,
            array_map(static fn (float $v): float => $v * $factor, $this->micronutrients),
        );
    }

    /** Sum two panels (component-wise, merging micronutrient keys). */
    public function add(self $other): self
    {
        $micros = $this->micronutrients;
        foreach ($other->micronutrients as $key => $value) {
            $micros[$key] = ($micros[$key] ?? 0.0) + $value;
        }

        return new self(
            $this->calories + $other->calories,
            $this->proteinGrams + $other->proteinGrams,
            $this->carbGrams + $other->carbGrams,
            $this->fatGrams + $other->fatGrams,
            $this->fibreGrams + $other->fibreGrams,
            $this->sugarGrams + $other->sugarGrams,
            $this->sodiumMg + $other->sodiumMg,
            $this->cholesterolMg + $other->cholesterolMg,
            $this->waterMl + $other->waterMl,
            $micros,
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        /** @var array<string, float> $micros */
        $micros = [];
        foreach (($data['micronutrients'] ?? []) as $key => $value) {
            $micros[(string) $key] = (float) $value;
        }

        return new self(
            (float) ($data['calories'] ?? 0),
            (float) ($data['protein_grams'] ?? 0),
            (float) ($data['carb_grams'] ?? 0),
            (float) ($data['fat_grams'] ?? 0),
            (float) ($data['fibre_grams'] ?? 0),
            (float) ($data['sugar_grams'] ?? 0),
            (float) ($data['sodium_mg'] ?? 0),
            (float) ($data['cholesterol_mg'] ?? 0),
            (float) ($data['water_ml'] ?? 0),
            $micros,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'calories' => round($this->calories, 2),
            'protein_grams' => round($this->proteinGrams, 2),
            'carb_grams' => round($this->carbGrams, 2),
            'fat_grams' => round($this->fatGrams, 2),
            'fibre_grams' => round($this->fibreGrams, 2),
            'sugar_grams' => round($this->sugarGrams, 2),
            'sodium_mg' => round($this->sodiumMg, 2),
            'cholesterol_mg' => round($this->cholesterolMg, 2),
            'water_ml' => round($this->waterMl, 2),
            'micronutrients' => array_map(static fn (float $v): float => round($v, 3), $this->micronutrients),
        ];
    }
}
