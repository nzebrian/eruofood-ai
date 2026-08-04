<?php

declare(strict_types=1);

use EruoFood\Identity\Infrastructure\Persistence\Eloquent\Model\UserModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function payUserToken(object $test, string $email = 'payer@example.com'): string
{
    Mail::fake();

    return $test->postJson('/api/v1/auth/register', [
        'name' => 'Payer',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data.tokens.access_token');
}

function payAdminToken(object $test, string $email = 'payadmin@example.com'): string
{
    Mail::fake();
    $test->postJson('/api/v1/auth/register', [
        'name' => 'Pay Admin',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated();
    UserModel::query()->where('email', $email)->update(['roles' => ['admin']]);

    return $test->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'Password123'])
        ->json('data.tokens.access_token');
}

it('initiates a payment that captures via the mock provider, then refunds it', function (): void {
    $token = payUserToken($this, 'flowpayer@example.com');

    // Initiate — the mock provider captures immediately.
    $intent = $this->withToken($token)->postJson('/api/v1/payments/payments', [
        'amount_minor' => 1000000,
        'customer_email' => 'flowpayer@example.com',
        'order_id' => '00000000-0000-0000-0000-0000000000ff',
    ])->assertCreated()->json('data');

    expect($intent['status'])->toBe('succeeded')->and($intent['provider'])->toBe('mock');
    $paymentId = $intent['payment_id'];

    // The payment reads back as succeeded and captured.
    $this->withToken($token)->getJson("/api/v1/payments/payments/{$paymentId}")
        ->assertOk()->assertJsonPath('data.status', 'succeeded');

    // Partial refund.
    $this->withToken($token)->postJson('/api/v1/payments/refunds', [
        'payment_id' => $paymentId, 'amount_minor' => 400000, 'reason' => 'Partial return',
    ])->assertCreated()->assertJsonPath('data.status', 'completed');

    $this->withToken($token)->getJson("/api/v1/payments/payments/{$paymentId}")
        ->assertOk()
        ->assertJsonPath('data.status', 'partially_refunded')
        ->assertJsonPath('data.refunded_minor', 400000);
});

it('is idempotent on the idempotency key', function (): void {
    $token = payUserToken($this, 'idem@example.com');
    $payload = [
        'amount_minor' => 250000,
        'customer_email' => 'idem@example.com',
        'idempotency_key' => 'order-123-attempt-1',
    ];
    $first = $this->withToken($token)->postJson('/api/v1/payments/payments', $payload)->assertCreated()->json('data.payment_id');
    $second = $this->withToken($token)->postJson('/api/v1/payments/payments', $payload)->assertCreated()->json('data.payment_id');
    expect($second)->toBe($first);
});

it('tops up a wallet and records the statement', function (): void {
    $token = payUserToken($this, 'wallet@example.com');

    $this->withToken($token)->getJson('/api/v1/payments/wallet')->assertOk()->assertJsonPath('data.balance_minor', 0);

    $this->withToken($token)->postJson('/api/v1/payments/wallet/topup', [
        'amount_minor' => 500000, 'customer_email' => 'wallet@example.com',
    ])->assertCreated();

    $this->withToken($token)->getJson('/api/v1/payments/wallet')->assertOk()->assertJsonPath('data.balance_minor', 500000);
    $this->withToken($token)->getJson('/api/v1/payments/wallet/statement')->assertOk()->assertJsonPath('data.0.type', 'topup');
});

it('exposes an admin financial report and settles a vendor', function (): void {
    $payer = payUserToken($this, 'reportpayer@example.com');
    $admin = payAdminToken($this, 'reportadmin@example.com');

    $this->withToken($payer)->postJson('/api/v1/payments/payments', [
        'amount_minor' => 1000000, 'customer_email' => 'reportpayer@example.com',
    ])->assertCreated();

    // 10% commission on ₦10,000 = ₦1,000.
    $this->withToken($admin)->getJson('/api/v1/payments/admin/report')
        ->assertOk()
        ->assertJsonPath('data.gross_minor', 1000000)
        ->assertJsonPath('data.commission_minor', 100000);

    // Settle a vendor to their wallet.
    $settlement = $this->withToken($admin)->postJson('/api/v1/payments/admin/settlements', [
        'payee_type' => 'vendor',
        'payee_id' => '00000000-0000-0000-0000-0000000000ab',
        'gross_minor' => 900000,
        'period_start' => '2026-07-01T00:00:00Z',
        'period_end' => '2026-07-31T23:59:59Z',
    ])->assertCreated()->json('data');

    expect($settlement['status'])->toBe('completed')
        ->and($settlement['commission_minor'])->toBe(90000)
        ->and($settlement['net_minor'])->toBe(810000);
});
