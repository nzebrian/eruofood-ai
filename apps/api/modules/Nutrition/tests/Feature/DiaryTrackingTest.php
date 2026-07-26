<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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
