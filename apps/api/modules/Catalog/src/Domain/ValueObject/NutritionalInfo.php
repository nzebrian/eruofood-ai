<?php

declare(strict_types=1);

namespace EruoFood\Catalog\Domain\ValueObject;

use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

/** Nutritional facts for a serving (or per 100g, per `basis`). */
final readonly class NutritionalInfo
{
    public function __construct(
        public int $calories,
        public float $proteinGrams,
        public float $carbohydrateGrams,
        public float $fatGrams,
        public float $fiberGrams = 0.0,
        public string $basis = 'per_serving',
    ) {
        foreach ([$calories, $proteinGrams, $carbohydrateGrams, $fatGrams, $fiberGrams] as $value) {
            if ($value < 0) {
                throw new InvalidArgumentException('Nutritional values cannot be negative.');
            }
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'calories' => $this->calories,
            'protein_grams' => $this->proteinGrams,
            'carbohydrate_grams' => $this->carbohydrateGrams,
            'fat_grams' => $this->fatGrams,
            'fiber_grams' => $this->fiberGrams,
            'basis' => $this->basis,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            calories: (int) ($data['calories'] ?? 0),
            proteinGrams: (float) ($data['protein_grams'] ?? 0),
            carbohydrateGrams: (float) ($data['carbohydrate_grams'] ?? 0),
            fatGrams: (float) ($data['fat_grams'] ?? 0),
            fiberGrams: (float) ($data['fiber_grams'] ?? 0),
            basis: (string) ($data['basis'] ?? 'per_serving'),
        );
    }
}
