<?php

declare(strict_types=1);

use EruoFood\Identity\Infrastructure\Persistence\Eloquent\Model\UserModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/** Register a user, promote to admin, and return a fresh admin access token. */
function catAdminToken(object $test, string $email = 'chef@example.com'): string
{
    Mail::fake();
    $test->postJson('/api/v1/auth/register', [
        'name' => 'Admin Chef',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated();

    UserModel::query()->where('email', $email)->update(['roles' => ['admin']]);

    return $test->postJson('/api/v1/auth/login', [
        'email' => $email,
        'password' => 'Password123',
    ])->json('data.tokens.access_token');
}

it('lets an admin create a category and publish a food, then serves it publicly', function (): void {
    $token = catAdminToken($this);

    $categoryId = $this->withToken($token)->postJson('/api/v1/admin/categories', [
        'name' => 'Rice',
        'type' => 'rice',
    ])->assertCreated()->json('data.id');

    $food = $this->withToken($token)->postJson('/api/v1/admin/foods', [
        'name' => 'Jollof Rice',
        'category_id' => $categoryId,
        'region' => 'south_west',
        'states' => ['Lagos'],
        'local_names' => [['name' => 'Jollof', 'language' => 'pidgin']],
        'nutrition' => ['calories' => 350, 'protein_grams' => 8, 'carbohydrate_grams' => 60, 'fat_grams' => 9],
        'tags' => ['party'],
    ])->assertCreated()->json('data');

    expect($food['status'])->toBe('draft');

    $this->withToken($token)->postJson("/api/v1/admin/foods/{$food['id']}/publish")
        ->assertOk()
        ->assertJsonPath('data.status', 'published');

    // Public browse + detail (no auth).
    $this->getJson('/api/v1/foods?q=jollof')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Jollof Rice');

    $this->getJson("/api/v1/foods/{$food['slug']}")
        ->assertOk()
        ->assertJsonPath('data.local_names.0.name', 'Jollof');
});

it('rejects food management for non-admins', function (): void {
    Mail::fake();
    $token = $this->postJson('/api/v1/auth/register', [
        'name' => 'Regular User',
        'email' => 'user@example.com',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data.tokens.access_token');

    $this->withToken($token)->postJson('/api/v1/admin/foods', [
        'name' => 'Test',
        'category_id' => '0193f8a0-1111-7abc-8def-0123456789ab',
        'region' => 'south_west',
    ])->assertStatus(403);
});

it('lists categories publicly', function (): void {
    $token = catAdminToken($this, 'chef2@example.com');
    $this->withToken($token)->postJson('/api/v1/admin/categories', ['name' => 'Soups', 'type' => 'soup'])->assertCreated();

    $this->getJson('/api/v1/categories')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Soups');
});
