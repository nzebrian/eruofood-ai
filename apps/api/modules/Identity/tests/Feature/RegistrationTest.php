<?php

declare(strict_types=1);

use EruoFood\Identity\Infrastructure\Mail\VerifyEmailMail;
use EruoFood\Identity\Infrastructure\Persistence\Eloquent\Model\UserModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

it('registers a new user and returns tokens', function (): void {
    Mail::fake();

    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.user.email', 'ada@example.com')
        ->assertJsonStructure(['data' => ['user' => ['id', 'roles'], 'tokens' => ['access_token', 'refresh_token', 'expires_in']]]);

    expect(UserModel::query()->where('email', 'ada@example.com')->exists())->toBeTrue();
    Mail::assertQueued(VerifyEmailMail::class);
});

it('rejects duplicate email registration', function (): void {
    Mail::fake();

    $payload = [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ];

    $this->postJson('/api/v1/auth/register', $payload)->assertCreated();
    $this->postJson('/api/v1/auth/register', $payload)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'EMAIL_ALREADY_REGISTERED');
});

it('validates the registration payload', function (): void {
    $this->postJson('/api/v1/auth/register', ['email' => 'nope'])
        ->assertStatus(422);
});
