<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function registerUser(object $test, string $email = 'ada@example.com'): void
{
    Mail::fake();
    $test->postJson('/api/v1/auth/register', [
        'name' => 'Ada Lovelace',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated();
}

it('logs in with valid credentials', function (): void {
    registerUser($this);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'ada@example.com',
        'password' => 'Password123',
    ])
        ->assertOk()
        ->assertJsonStructure(['data' => ['user', 'tokens' => ['access_token', 'refresh_token']]]);
});

it('rejects invalid credentials', function (): void {
    registerUser($this);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'ada@example.com',
        'password' => 'WrongPassword1',
    ])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
});

it('refreshes an access token', function (): void {
    registerUser($this);

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => 'ada@example.com',
        'password' => 'Password123',
    ])->json('data');

    $this->postJson('/api/v1/auth/refresh', [
        'refresh_token' => $login['tokens']['refresh_token'],
    ])
        ->assertOk()
        ->assertJsonStructure(['data' => ['access_token', 'refresh_token']]);
});
