<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/** Register a user and return a fresh access token (shared by the Nutrition suite). */
function nutUserToken(object $test, string $email = 'nut@example.com'): string
{
    Mail::fake();

    return $test->postJson('/api/v1/auth/register', [
        'name' => 'Nutri User',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data.tokens.access_token');
}

/** @return array<string, mixed> */
function nutProfilePayload(): array
{
    return [
        'weight_kg' => 80,
        'height_cm' => 180,
        'age' => 30,
        'gender' => 'male',
        'activity_level' => 'moderate',
        'goal' => 'maintain',
        'dietary_preferences' => ['halal'],
        'allergies' => ['peanut'],
    ];
}

it('saves a health profile and returns a full assessment', function (): void {
    $token = nutUserToken($this);

    $this->withToken($token)->putJson('/api/v1/nutrition/profile', nutProfilePayload())
        ->assertOk()
        ->assertJsonPath('data.goal', 'maintain')
        ->assertJsonPath('data.allergies.0', 'peanut');

    // Assessment for the saved profile (BMI 24.7, BMR 1780, TDEE 2759).
    $this->withToken($token)->getJson('/api/v1/nutrition/assessment')
        ->assertOk()
        ->assertJsonPath('data.bmi', 24.7)
        ->assertJsonPath('data.bmi_category', 'normal')
        ->assertJsonPath('data.bmr', 1780)
        ->assertJsonPath('data.tdee', 2759)
        ->assertJsonPath('data.calorie_target', 2759);
});

it('requires a profile before assessing', function (): void {
    $token = nutUserToken($this, 'noprofile@example.com');

    $this->withToken($token)->getJson('/api/v1/nutrition/assessment')->assertStatus(422);
});

it('calculates ad-hoc without a saved profile', function (): void {
    $this->postJson('/api/v1/nutrition/calculate', [
        'weight_kg' => 80, 'height_cm' => 180, 'age' => 30, 'gender' => 'male',
        'activity_level' => 'moderate', 'goal' => 'lose_weight',
    ])->assertOk()->assertJsonPath('data.calorie_target', 2259); // 2759 - 500
});
