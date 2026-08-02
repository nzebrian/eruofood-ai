<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * Register a platform user and return their JWT access token.
 */
function devToken(object $test, string $email): string
{
    Mail::fake();

    return $test->postJson('/api/v1/auth/register', [
        'name' => 'Dev', 'email' => $email,
        'password' => 'Password123', 'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data.tokens.access_token');
}

/**
 * Full portal bootstrap: developer → application (scoped) → issued API key.
 * Returns the one-time plaintext key.
 */
function issueKey(object $test, string $token, array $scopes): string
{
    $test->withToken($token)->postJson('/api/v1/developer/register', ['name' => 'Dev', 'email' => 'dev@example.com'])->assertCreated();
    $appId = $test->withToken($token)->postJson('/api/v1/developer/applications', [
        'name' => 'My App', 'description' => 'test', 'scopes' => $scopes,
    ])->assertCreated()->json('data.id');

    return $test->withToken($token)->postJson("/api/v1/developer/applications/{$appId}/keys", [
        'name' => 'Prod', 'scopes' => $scopes,
    ])->assertCreated()->json('data.key');
}

it('exposes public status without authentication', function (): void {
    $this->getJson('/api/public/v1/status')
        ->assertOk()
        ->assertJsonPath('data.status', 'ok')
        ->assertJsonPath('data.version', 'v1');
});

it('rejects the public data API without an API key', function (): void {
    $this->getJson('/api/public/v1/foods')->assertStatus(401)
        ->assertJsonPath('error.code', 'PUBLICAPI_UNAUTHENTICATED');
});

it('serves the public data API with a scoped API key and sets rate-limit headers', function (): void {
    $token = devToken($this, 'a@example.com');
    $key = issueKey($this, $token, ['foods:read']);

    $this->withHeader('X-Api-Key', $key)->getJson('/api/public/v1/foods')
        ->assertOk()
        ->assertJsonStructure(['data', 'meta' => ['pagination' => ['page', 'per_page', 'total', 'last_page', 'has_more'], 'version']])
        ->assertHeader('X-RateLimit-Limit')
        ->assertHeader('X-RateLimit-Remaining')
        ->assertHeader('X-Request-Id');
});

it('forbids a scope the key was not granted', function (): void {
    $token = devToken($this, 'b@example.com');
    $key = issueKey($this, $token, ['foods:read']); // no recipes:read

    $this->withHeader('X-Api-Key', $key)->getJson('/api/public/v1/recipes')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'PUBLICAPI_FORBIDDEN');
});

it('never returns the key secret when listing keys', function (): void {
    $token = devToken($this, 'c@example.com');
    $this->withToken($token)->postJson('/api/v1/developer/register', ['name' => 'Dev', 'email' => 'c@example.com'])->assertCreated();
    $appId = $this->withToken($token)->postJson('/api/v1/developer/applications', ['name' => 'A', 'scopes' => ['foods:read']])->json('data.id');
    $this->withToken($token)->postJson("/api/v1/developer/applications/{$appId}/keys", ['name' => 'K', 'scopes' => ['foods:read']])->assertCreated();

    $list = $this->withToken($token)->getJson("/api/v1/developer/applications/{$appId}/keys")->assertOk()->json('data.keys');
    expect($list)->toHaveCount(1);
    expect($list[0])->not->toHaveKey('key');
    expect($list[0])->not->toHaveKey('hashed_secret');
    expect($list[0]['prefix'])->toStartWith('efk_');
});

it('revokes a key so it can no longer authenticate', function (): void {
    $token = devToken($this, 'd@example.com');
    $key = issueKey($this, $token, ['foods:read']);
    $this->withHeader('X-Api-Key', $key)->getJson('/api/public/v1/foods')->assertOk();

    // Find the key id and revoke it.
    $appId = $this->withToken($token)->getJson('/api/v1/developer/applications')->json('data.0.id');
    $keyId = $this->withToken($token)->getJson("/api/v1/developer/applications/{$appId}/keys")->json('data.keys.0.id');
    $this->withToken($token)->deleteJson("/api/v1/developer/keys/{$keyId}")->assertOk();

    $this->withHeader('X-Api-Key', $key)->getJson('/api/public/v1/foods')->assertStatus(401);
});
