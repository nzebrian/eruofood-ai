<?php

declare(strict_types=1);

use EruoFood\Nutrition\Domain\ValueObject\NutritionFacts;
use EruoFood\Shared\Domain\Exception\InvalidArgumentException;

it('scales every quantity including micronutrients', function (): void {
    $facts = new NutritionFacts(200, 10, 20, 8, 3, 4, 300, 20, 100, ['iron_mg' => 2.0]);
    $scaled = $facts->scale(2.5);

    expect($scaled->calories)->toBe(500.0)
        ->and($scaled->proteinGrams)->toBe(25.0)
        ->and($scaled->sodiumMg)->toBe(750.0)
        ->and($scaled->micronutrients['iron_mg'])->toBe(5.0);
});

it('sums two panels and merges micronutrient keys', function (): void {
    $a = new NutritionFacts(200, 10, 20, 8, 0, 0, 0, 0, 0, ['iron_mg' => 2.0, 'vitamin_c_mg' => 5.0]);
    $b = new NutritionFacts(100, 5, 10, 4, 0, 0, 0, 0, 0, ['iron_mg' => 1.5]);
    $sum = $a->add($b);

    expect($sum->calories)->toBe(300.0)
        ->and($sum->proteinGrams)->toBe(15.0)
        ->and($sum->micronutrients['iron_mg'])->toBe(3.5)
        ->and($sum->micronutrients['vitamin_c_mg'])->toBe(5.0);
});

it('round-trips through arrays', function (): void {
    $data = [
        'calories' => 250, 'protein_grams' => 12, 'carb_grams' => 30, 'fat_grams' => 9,
        'fibre_grams' => 4, 'sugar_grams' => 6, 'sodium_mg' => 400, 'cholesterol_mg' => 15,
        'water_ml' => 120, 'micronutrients' => ['iron_mg' => 2.5],
    ];
    $facts = NutritionFacts::fromArray($data);

    expect($facts->toArray()['calories'])->toBe(250.0)
        ->and($facts->toArray()['micronutrients']['iron_mg'])->toBe(2.5);
});

it('rejects negative quantities', function (): void {
    new NutritionFacts(-1, 0, 0, 0);
})->throws(InvalidArgumentException::class);

it('starts empty at zero', function (): void {
    expect(NutritionFacts::empty()->calories)->toBe(0.0);
});
