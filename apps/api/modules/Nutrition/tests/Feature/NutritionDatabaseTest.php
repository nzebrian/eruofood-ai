<?php

declare(strict_types=1);

use EruoFood\Identity\Infrastructure\Persistence\Eloquent\Model\UserModel;
use EruoFood\Nutrition\Infrastructure\Seeder\NutritionItemSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function nutAdminToken(object $test, string $email = 'nutadmin@example.com'): string
{
    Mail::fake();
    $test->postJson('/api/v1/auth/register', [
        'name' => 'Nutri Admin',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated();

    UserModel::query()->where('email', $email)->update(['roles' => ['admin']]);

    return $test->postJson('/api/v1/auth/login', [
        'email' => $email, 'password' => 'Password123',
    ])->json('data.tokens.access_token');
}

it('serves the seeded nutrition database and analyses a meal', function (): void {
    $this->seed(NutritionItemSeeder::class);

    $item = $this->getJson('/api/v1/nutrition/items?q=jollof')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Jollof Rice')
        ->json('data.0');

    // Analyse two servings of Jollof (330 kcal each => 660).
    $this->postJson('/api/v1/nutrition/analyse', [
        'items' => [['nutrition_item_id' => $item['id'], 'servings' => 2]],
    ])->assertOk()
        ->assertJsonPath('data.totals.calories', 660)
        ->assertJsonPath('data.items.0.name', 'Jollof Rice');
});

it('lets an admin create a nutrition item and blocks non-admins', function (): void {
    $admin = nutAdminToken($this);

    $this->withToken($admin)->postJson('/api/v1/admin/nutrition/items', [
        'name' => 'Test Ogbono Soup',
        'category' => 'soup',
        'serving_size' => ['label' => '1 bowl', 'grams' => 200],
        'nutrition' => ['calories' => 300, 'protein_grams' => 16, 'carb_grams' => 12, 'fat_grams' => 22],
    ])->assertCreated()->assertJsonPath('data.slug', 'test-ogbono-soup');

    $user = nutUserToken($this, 'plainuser@example.com');
    $this->withToken($user)->postJson('/api/v1/admin/nutrition/items', [
        'name' => 'Nope',
        'serving_size' => ['label' => '1', 'grams' => 100],
        'nutrition' => ['calories' => 1, 'protein_grams' => 1, 'carb_grams' => 1, 'fat_grams' => 1],
    ])->assertStatus(403);
});
