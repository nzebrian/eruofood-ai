<?php

declare(strict_types=1);

use EruoFood\Catalog\Domain\Enum\Difficulty;
use EruoFood\Catalog\Domain\Enum\MeasurementUnit;
use EruoFood\Catalog\Domain\Recipe\Recipe;
use EruoFood\Catalog\Domain\ValueObject\CookingStep;
use EruoFood\Catalog\Domain\ValueObject\Measurement;
use EruoFood\Catalog\Domain\ValueObject\RecipeIngredient;
use EruoFood\Shared\Domain\ValueObject\Slug;

function makeRecipe(): Recipe
{
    return Recipe::create(
        id: 'r1',
        foodId: 'food-1',
        authorId: 'user-1',
        title: 'Classic Jollof',
        slug: Slug::fromTitle('Classic Jollof'),
        prepTimeMinutes: 20,
        cookTimeMinutes: 45,
        difficulty: Difficulty::Medium,
        servingSize: 6,
        ingredients: [new RecipeIngredient('Rice', new Measurement(4, MeasurementUnit::Cup))],
        steps: [
            new CookingStep(2, 'Add rice and cook'),
            new CookingStep(1, 'Fry the base'),
        ],
    );
}

it('starts at version 1 and sorts steps by order', function (): void {
    $recipe = makeRecipe();

    expect($recipe->version())->toBe(1)
        ->and($recipe->totalTimeMinutes())->toBe(65)
        ->and($recipe->steps()[0]->order)->toBe(1)
        ->and($recipe->steps()[1]->order)->toBe(2);
});

it('bumps the version when content is updated', function (): void {
    $recipe = makeRecipe();
    $recipe->updateContent(
        title: 'Classic Jollof Rice',
        slug: Slug::fromTitle('Classic Jollof Rice'),
        summary: null,
        prepTimeMinutes: 15,
        cookTimeMinutes: 40,
        difficulty: Difficulty::Easy,
        servingSize: 4,
        ingredients: $recipe->ingredients(),
        steps: $recipe->steps(),
        tips: [],
        tags: [],
    );

    expect($recipe->version())->toBe(2)
        ->and($recipe->servingSize())->toBe(4);
});

it('recomputes the rating summary and checks ownership', function (): void {
    $recipe = makeRecipe();
    $recipe->applyRatingSummary(4.6667, 3);

    expect($recipe->ratingAverage())->toBe(4.67)
        ->and($recipe->ratingCount())->toBe(3)
        ->and($recipe->isOwnedBy('user-1'))->toBeTrue()
        ->and($recipe->isOwnedBy('user-2'))->toBeFalse();
});
