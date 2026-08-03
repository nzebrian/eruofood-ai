<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a plan, generates a merged shopping list, and adjusts portions', function (): void {
    $token = nutUserToken($this, 'planner@example.com');

    $plan = $this->withToken($token)->postJson('/api/v1/nutrition/meal-plans', [
        'title' => 'Week 1',
        'period' => 'weekly',
        'start_date' => '2026-07-27',
        'entries' => [
            ['date' => '2026-07-27', 'meal_type' => 'breakfast', 'label' => 'Akara', 'servings' => 1, 'estimated_cost' => 300],
            ['date' => '2026-07-27', 'meal_type' => 'lunch', 'label' => 'Jollof Rice', 'servings' => 2, 'estimated_cost' => 800],
            ['date' => '2026-07-28', 'meal_type' => 'breakfast', 'label' => 'Akara', 'servings' => 1, 'estimated_cost' => 300],
        ],
    ])->assertCreated()->json('data');

    // JSON serialises a whole-number float as `1400`, which decodes to int, so
    // compare the numeric value (identical on SQLite and PostgreSQL).
    expect((float) $plan['estimated_cost'])->toBe(1400.0);
    $planId = $plan['id'];

    // Shopping list merges the two Akara lines (2 servings, 600) + Jollof (2, 800).
    $this->withToken($token)->getJson("/api/v1/nutrition/meal-plans/{$planId}/shopping-list")
        ->assertOk()
        ->assertJsonPath('data.total_estimated_cost', 1400)
        ->assertJsonCount(2, 'data.items');

    // Portion adjustment doubles every serving and its cost.
    $this->withToken($token)->postJson("/api/v1/nutrition/meal-plans/{$planId}/adjust", ['factor' => 2])
        ->assertOk()
        ->assertJsonPath('data.estimated_cost', 2800);

    // Listing shows the plan.
    $this->withToken($token)->getJson('/api/v1/nutrition/meal-plans')
        ->assertOk()
        ->assertJsonPath('data.0.id', $planId);
});

it('keeps meal plans private to their owner', function (): void {
    $owner = nutUserToken($this, 'owner-plan@example.com');
    $other = nutUserToken($this, 'other-plan@example.com');

    $id = $this->withToken($owner)->postJson('/api/v1/nutrition/meal-plans', [
        'title' => 'Mine', 'period' => 'daily', 'start_date' => '2026-07-27', 'entries' => [],
    ])->assertCreated()->json('data.id');

    $this->withToken($other)->getJson("/api/v1/nutrition/meal-plans/{$id}")->assertStatus(404);
});
