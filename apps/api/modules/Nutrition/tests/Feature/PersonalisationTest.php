<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns AI meal recommendations for a user with a profile', function (): void {
    $token = nutUserToken($this, 'perso@example.com');
    $this->withToken($token)->putJson('/api/v1/nutrition/profile', nutProfilePayload())->assertOk();

    // Goes Nutrition -> AiAdvisor contract -> AI gateway (mock provider in tests).
    $response = $this->withToken($token)->getJson('/api/v1/nutrition/recommendations/meals')->assertOk();

    expect($response->json('data.advice'))->toBeString()->not->toBe('');
    $response->assertJsonPath('data.meta.provider', 'mock');
});

it('requires a profile for personalisation', function (): void {
    $token = nutUserToken($this, 'perso2@example.com');

    $this->withToken($token)->getJson('/api/v1/nutrition/recommendations/meals')->assertStatus(422);
});

it('records and lists progress measurements', function (): void {
    $token = nutUserToken($this, 'progress@example.com');

    $this->withToken($token)->postJson('/api/v1/nutrition/progress', [
        'date' => '2026-07-20', 'weight_kg' => 82.5, 'note' => 'start',
    ])->assertCreated();
    $this->withToken($token)->postJson('/api/v1/nutrition/progress', [
        'date' => '2026-07-27', 'weight_kg' => 81.0,
    ])->assertCreated();

    // Newest first.
    $this->withToken($token)->getJson('/api/v1/nutrition/progress')
        ->assertOk()
        ->assertJsonPath('data.0.date', '2026-07-27')
        ->assertJsonPath('data.0.weight_kg', 81)
        ->assertJsonPath('data.1.date', '2026-07-20');
});
