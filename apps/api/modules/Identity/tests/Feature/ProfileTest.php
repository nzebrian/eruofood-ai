<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/** @return array{token: string} */
function authenticate(object $test): array
{
    Mail::fake();
    $data = $test->postJson('/api/v1/auth/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data');

    return ['token' => $data['tokens']['access_token']];
}

it('returns the authenticated user profile', function (): void {
    ['token' => $token] = authenticate($this);

    $this->withToken($token)->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.email', 'ada@example.com')
        ->assertJsonPath('data.roles', ['user']);
});

it('rejects unauthenticated profile access', function (): void {
    $this->getJson('/api/v1/me')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

it('updates the profile name and phone', function (): void {
    ['token' => $token] = authenticate($this);

    $this->withToken($token)->putJson('/api/v1/me', [
        'name' => 'Ada King',
        'phone' => '+2348012345678',
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Ada King')
        ->assertJsonPath('data.phone', '+2348012345678');
});

it('changes the password with the correct current password', function (): void {
    ['token' => $token] = authenticate($this);

    $this->withToken($token)->putJson('/api/v1/me/password', [
        'current_password' => 'Password123',
        'password' => 'NewPassword456',
        'password_confirmation' => 'NewPassword456',
    ])->assertOk();

    // Old password no longer works.
    $this->postJson('/api/v1/auth/login', [
        'email' => 'ada@example.com',
        'password' => 'Password123',
    ])->assertStatus(401);
});
