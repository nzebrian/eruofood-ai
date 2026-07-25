<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/** @return array{token: string, foodId: string} */
function catSeedFood(object $test): array
{
    $admin = catAdminToken($test, 'admin@example.com');
    $categoryId = $test->withToken($admin)->postJson('/api/v1/admin/categories', [
        'name' => 'Rice', 'type' => 'rice',
    ])->json('data.id');

    $food = $test->withToken($admin)->postJson('/api/v1/admin/foods', [
        'name' => 'Jollof Rice',
        'category_id' => $categoryId,
        'region' => 'south_west',
    ])->json('data');
    $test->withToken($admin)->postJson("/api/v1/admin/foods/{$food['id']}/publish");

    return ['token' => $admin, 'foodId' => $food['id']];
}

function catUserToken(object $test, string $email = 'cook@example.com'): string
{
    Mail::fake();

    return $test->postJson('/api/v1/auth/register', [
        'name' => 'Home Cook',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data.tokens.access_token');
}

it('creates a recipe, reviews it, and favourites it', function (): void {
    ['foodId' => $foodId] = catSeedFood($this);
    $token = catUserToken($this);

    $recipe = $this->withToken($token)->postJson('/api/v1/recipes', [
        'food_id' => $foodId,
        'title' => 'My Jollof',
        'prep_time_minutes' => 20,
        'cook_time_minutes' => 45,
        'difficulty' => 'medium',
        'serving_size' => 6,
        'ingredients' => [['name' => 'Rice', 'amount' => 4, 'unit' => 'cup']],
        'steps' => [['order' => 1, 'instruction' => 'Cook the rice']],
        'tags' => ['party'],
    ])->assertCreated()->json('data');

    expect($recipe['version'])->toBe(1);

    // Review updates the recipe's average rating.
    $this->withToken($token)->postJson("/api/v1/recipes/{$recipe['id']}/reviews", [
        'rating' => 5,
        'comment' => 'Delicious!',
    ])->assertCreated();

    $this->getJson("/api/v1/recipes/{$recipe['slug']}")
        ->assertOk()
        ->assertJsonPath('data.rating_average', 5)
        ->assertJsonPath('data.rating_count', 1);

    // Favourite + list.
    $this->withToken($token)->postJson("/api/v1/me/favourites/{$recipe['id']}")->assertCreated();
    $this->withToken($token)->getJson('/api/v1/me/favourites')
        ->assertOk()
        ->assertJsonPath('data.0.id', $recipe['id']);
});

it('bumps the recipe version on update and blocks non-owners', function (): void {
    ['foodId' => $foodId] = catSeedFood($this);
    $ownerToken = catUserToken($this, 'owner@example.com');
    $otherToken = catUserToken($this, 'other@example.com');

    $recipe = $this->withToken($ownerToken)->postJson('/api/v1/recipes', [
        'food_id' => $foodId,
        'title' => 'Owned Recipe',
        'prep_time_minutes' => 10,
        'cook_time_minutes' => 20,
        'difficulty' => 'easy',
        'serving_size' => 2,
        'ingredients' => [['name' => 'Rice', 'amount' => 2, 'unit' => 'cup']],
        'steps' => [['order' => 1, 'instruction' => 'Cook']],
    ])->json('data');

    // Non-owner cannot update.
    $this->withToken($otherToken)->putJson("/api/v1/recipes/{$recipe['id']}", [
        'food_id' => $foodId,
        'title' => 'Hacked',
        'prep_time_minutes' => 10,
        'cook_time_minutes' => 20,
        'difficulty' => 'easy',
        'serving_size' => 2,
        'ingredients' => [['name' => 'Rice', 'amount' => 2, 'unit' => 'cup']],
        'steps' => [['order' => 1, 'instruction' => 'Cook']],
    ])->assertStatus(403);

    // Owner update bumps version and records history.
    $this->withToken($ownerToken)->putJson("/api/v1/recipes/{$recipe['id']}", [
        'food_id' => $foodId,
        'title' => 'Owned Recipe v2',
        'prep_time_minutes' => 12,
        'cook_time_minutes' => 22,
        'difficulty' => 'medium',
        'serving_size' => 3,
        'ingredients' => [['name' => 'Rice', 'amount' => 3, 'unit' => 'cup']],
        'steps' => [['order' => 1, 'instruction' => 'Cook well']],
    ])->assertOk()->assertJsonPath('data.version', 2);

    $this->getJson("/api/v1/recipes/{$recipe['id']}/versions")
        ->assertOk()
        ->assertJsonPath('data.0.version', 2);
});
