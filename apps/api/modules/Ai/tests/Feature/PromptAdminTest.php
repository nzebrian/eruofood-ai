<?php

declare(strict_types=1);

use EruoFood\Ai\Infrastructure\Seeder\DefaultPromptSeeder;
use EruoFood\Identity\Infrastructure\Persistence\Eloquent\Model\UserModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function aiAdminToken(object $test, string $email = 'promptadmin@example.com'): string
{
    Mail::fake();
    $test->postJson('/api/v1/auth/register', [
        'name' => 'Prompt Admin',
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

it('lets an admin publish and activate a new prompt version', function (): void {
    $this->seed(DefaultPromptSeeder::class);
    $token = aiAdminToken($this);

    // Version 1 was seeded; publishing creates version 2 and activates it.
    $created = $this->withToken($token)->postJson('/api/v1/admin/ai/prompts', [
        'feature' => 'cooking_tips',
        'name' => 'Cooking tips v2',
        'system_template' => 'You are a concise coach.',
        'user_template' => 'Tips for {{ topic }} at {{ skill_level }} level.',
        'variables' => ['topic', 'skill_level'],
        'activate' => true,
    ])->assertCreated();

    $created->assertJsonPath('data.version', 2)->assertJsonPath('data.active', true);

    // The version history now lists two versions, newest first.
    $this->withToken($token)->getJson('/api/v1/admin/ai/prompts?feature=cooking_tips')
        ->assertOk()
        ->assertJsonPath('data.0.version', 2)
        ->assertJsonPath('data.1.version', 1)
        ->assertJsonPath('data.1.active', false);
});

it('blocks non-admins from prompt management', function (): void {
    $this->seed(DefaultPromptSeeder::class);
    Mail::fake();
    $token = $this->postJson('/api/v1/auth/register', [
        'name' => 'Regular',
        'email' => 'regular@example.com',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data.tokens.access_token');

    $this->withToken($token)->getJson('/api/v1/admin/ai/prompts?feature=cooking_tips')->assertStatus(403);
});
