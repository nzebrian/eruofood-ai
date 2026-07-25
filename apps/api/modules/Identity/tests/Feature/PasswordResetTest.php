<?php

declare(strict_types=1);

use EruoFood\Identity\Infrastructure\Mail\ResetPasswordMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

it('accepts a forgot-password request without leaking account existence', function (): void {
    Mail::fake();

    // Unknown email still returns 202 (no enumeration).
    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'ghost@example.com'])
        ->assertStatus(202);

    Mail::assertNothingQueued();
});

it('emails a reset link to a known account', function (): void {
    Mail::fake();
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated();

    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'ada@example.com'])
        ->assertStatus(202);

    Mail::assertQueued(ResetPasswordMail::class);
});
