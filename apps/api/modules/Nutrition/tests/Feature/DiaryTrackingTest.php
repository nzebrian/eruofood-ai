<?php

declare(strict_types=1);

use EruoFood\Nutrition\Infrastructure\Seeder\NutritionItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('logs a food by referencing a nutrition-database item and scales its facts', function (): void {
    $this->seed(NutritionItemSeeder::class);
    $token = nutUserToken($this, 'itemdiary@example.com');

    // Jollof Rice is seeded at 330 kcal/serving.
    $jollofId = $this->getJson('/api/v1/nutrition/items?q=jollof')->assertOk()->json('data.0.id');

    $entry = $this->withToken($token)->postJson('/api/v1/nutrition/diary', [
        'date' => '2026-07-26',
        'meal_type' => 'lunch',
        'servings' => 2,
        'nutrition_item_id' => $jollofId,
    ])->assertCreated();

    // The item's facts are resolved server-side and scaled to 2 servings (660 kcal).
    $entry->assertJsonPath('data.item_name', 'Jollof Rice')
        ->assertJsonPath('data.nutrition.calories', 660);

    $this->withToken($token)->getJson('/api/v1/nutrition/diary?date=2026-07-26')
        ->assertOk()
        ->assertJsonPath('data.totals.calories', 660);
});

it('rejects logging an unknown nutrition item', function (): void {
    $token = nutUserToken($this, 'baditem@example.com');

    $this->withToken($token)->postJson('/api/v1/nutrition/diary', [
        'date' => '2026-07-26',
        'meal_type' => 'lunch',
        'servings' => 1,
        'nutrition_item_id' => '0193f8a0-1111-7abc-8def-0123456789ab',
    ])->assertStatus(404);
});

it('logs custom foods and totals a day against targets', function (): void {
    $token = nutUserToken($this, 'diary@example.com');
    $this->withToken($token)->putJson('/api/v1/nutrition/profile', nutProfilePayload())->assertOk();

    // Log two custom foods for today.
    $this->withToken($token)->postJson('/api/v1/nutrition/diary', [
        'date' => '2026-07-26',
        'meal_type' => 'breakfast',
        'servings' => 1,
        'item_name' => 'Akara',
        'nutrition' => ['calories' => 220, 'protein_grams' => 8, 'carb_grams' => 18, 'fat_grams' => 13],
    ])->assertCreated();

    $this->withToken($token)->postJson('/api/v1/nutrition/diary', [
        'date' => '2026-07-26',
        'meal_type' => 'lunch',
        'servings' => 2,
        'item_name' => 'Jollof Rice',
        'nutrition' => ['calories' => 330, 'protein_grams' => 7, 'carb_grams' => 55, 'fat_grams' => 9],
    ])->assertCreated();

    // Day totals: 220 + 2*330 = 880 kcal; targets present (profile set).
    $this->withToken($token)->getJson('/api/v1/nutrition/diary?date=2026-07-26')
        ->assertOk()
        ->assertJsonPath('data.totals.calories', 880)
        ->assertJsonPath('data.targets.calorie_target', 2759)
        ->assertJsonPath('data.remaining_calories', 1879); // 2759 - 880
});

it('deletes a diary entry', function (): void {
    $token = nutUserToken($this, 'diary2@example.com');

    $id = $this->withToken($token)->postJson('/api/v1/nutrition/diary', [
        'date' => '2026-07-26',
        'meal_type' => 'snack',
        'servings' => 1,
        'item_name' => 'Chin Chin',
        'nutrition' => ['calories' => 150, 'protein_grams' => 2, 'carb_grams' => 20, 'fat_grams' => 7],
    ])->assertCreated()->json('data.id');

    $this->withToken($token)->deleteJson("/api/v1/nutrition/diary/{$id}")->assertNoContent();
    $this->withToken($token)->getJson('/api/v1/nutrition/diary?date=2026-07-26')
        ->assertOk()
        ->assertJsonPath('data.totals.calories', 0);
});
