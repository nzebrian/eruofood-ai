<?php

declare(strict_types=1);

use EruoFood\Ai\Infrastructure\Seeder\DefaultPromptSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/** Register a user and return a fresh access token. */
function aiUserToken(object $test, string $email = 'aicook@example.com'): string
{
    Mail::fake();

    return $test->postJson('/api/v1/auth/register', [
        'name' => 'AI Cook',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data.tokens.access_token');
}

it('generates a recipe through the mock provider and logs usage', function (): void {
    $this->seed(DefaultPromptSeeder::class);
    $token = aiUserToken($this);

    $response = $this->withToken($token)->postJson('/api/v1/ai/recipes/generate', [
        'dish_name' => 'Jollof Rice',
        'servings' => 4,
        'difficulty' => 'medium',
        'dietary_preferences' => ['halal'],
        'available_ingredients' => ['rice', 'tomatoes'],
    ])->assertOk();

    $response
        ->assertJsonPath('data.meta.provider', 'mock')
        ->assertJsonPath('data.meta.cached', false)
        ->assertJsonPath('data.content.title', 'Mock Jollof Rice');

    expect($response->json('data.content.ingredients'))->toBeArray();

    // The call was attributed to the usage/cost ledger.
    $this->assertDatabaseCount('ai_usage_logs', 1);
});

it('serves an identical second request from cache', function (): void {
    // Enable caching for this test only (disabled globally in phpunit.xml).
    config()->set('ai.cache.enabled', true);
    config()->set('cache.default', 'array');
    $this->app->forgetInstance(\EruoFood\Ai\Application\DTO\GatewaySettings::class);
    $this->app->instance(
        \EruoFood\Ai\Application\DTO\GatewaySettings::class,
        new \EruoFood\Ai\Application\DTO\GatewaySettings(true, 3600, 2, 0),
    );
    $this->app->bind(
        \EruoFood\Ai\Application\Port\AiResponseCache::class,
        fn ($app) => new \EruoFood\Ai\Infrastructure\Cache\LaravelAiResponseCache($app->make('cache')->store('array'), 'ai:resp:'),
    );

    $this->seed(DefaultPromptSeeder::class);
    $token = aiUserToken($this);

    $payload = ['dish_name' => 'Egusi Soup', 'servings' => 2];
    $this->withToken($token)->postJson('/api/v1/ai/recipes/summarize', ['content' => 'A long recipe about egusi soup.'])->assertOk();
    $first = $this->withToken($token)->postJson('/api/v1/ai/recipes/generate', $payload)->assertOk();
    $second = $this->withToken($token)->postJson('/api/v1/ai/recipes/generate', $payload)->assertOk();

    expect($first->json('data.meta.cached'))->toBeFalse()
        ->and($second->json('data.meta.cached'))->toBeTrue();
});

it('rejects unauthenticated generation', function (): void {
    $this->postJson('/api/v1/ai/recipes/generate', ['dish_name' => 'Jollof'])->assertStatus(401);
});

it('validates the request body', function (): void {
    $this->seed(DefaultPromptSeeder::class);
    $token = aiUserToken($this);

    $this->withToken($token)->postJson('/api/v1/ai/recipes/generate', ['servings' => 4])
        ->assertStatus(422);
});
