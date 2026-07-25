<?php

declare(strict_types=1);

use EruoFood\Catalog\Domain\Enum\ContentStatus;
use EruoFood\Catalog\Domain\Enum\FoodRegion;
use EruoFood\Catalog\Domain\Event\FoodPublished;
use EruoFood\Catalog\Domain\Food\Food;
use EruoFood\Shared\Domain\ValueObject\Slug;

function makeFood(): Food
{
    return Food::create(
        id: 'food-1',
        name: 'Jollof Rice',
        slug: Slug::fromTitle('Jollof Rice'),
        categoryId: 'cat-1',
        region: FoodRegion::SouthWest,
    );
}

it('creates a food in draft with a derived slug', function (): void {
    $food = makeFood();

    expect($food->status())->toBe(ContentStatus::Draft)
        ->and((string) $food->slug())->toBe('jollof-rice')
        ->and($food->region())->toBe(FoodRegion::SouthWest);
});

it('publishes a food and records the event once', function (): void {
    $food = makeFood();
    $food->publish();
    $food->publish(); // idempotent

    expect($food->status())->toBe(ContentStatus::Published);
    $events = $food->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(FoodPublished::class);
});

it('adds and removes images', function (): void {
    $food = makeFood();
    $food->addImage('foods/food-1/a.jpg');
    $food->addImage('foods/food-1/b.jpg');
    $food->removeImage('foods/food-1/a.jpg');

    expect($food->images())->toBe(['foods/food-1/b.jpg']);
});
