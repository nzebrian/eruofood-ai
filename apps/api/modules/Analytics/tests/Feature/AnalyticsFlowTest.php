<?php

declare(strict_types=1);

use EruoFood\Identity\Infrastructure\Persistence\Eloquent\Model\UserModel;
use EruoFood\Payments\Domain\Event\PaymentSucceeded;
use EruoFood\Shared\Domain\EventBus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function analyticsAdmin(object $test, string $email = 'bi-admin@example.com'): string
{
    Mail::fake();
    $test->postJson('/api/v1/auth/register', [
        'name' => 'BI Admin',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated();
    UserModel::query()->where('email', $email)->update(['roles' => ['admin']]);

    return $test->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'Password123'])
        ->json('data.tokens.access_token');
}

function analyticsUser(object $test, string $email = 'bi-user@example.com'): string
{
    Mail::fake();

    return $test->postJson('/api/v1/auth/register', [
        'name' => 'BI User',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data.tokens.access_token');
}

it('collects domain events into metrics and surfaces them on the executive dashboard', function (): void {
    $token = analyticsAdmin($this, 'exec@example.com');

    // Business modules publish events on the shared bus — Payments here.
    app(EventBus::class)->publish(new PaymentSucceeded('11111111-1111-4111-8111-111111111101', '11111111-1111-4111-8111-1111111110a1', '22222222-2222-4222-8222-222222222201', 1000000, 'NGN', 'paystack'));
    app(EventBus::class)->publish(new PaymentSucceeded('11111111-1111-4111-8111-111111111102', '11111111-1111-4111-8111-1111111110a2', '22222222-2222-4222-8222-222222222202', 500000, 'NGN', 'flutterwave'));

    $dashboard = $this->withToken($token)->getJson('/api/v1/analytics/dashboards/executive')
        ->assertOk()->json('data');

    expect($dashboard['kpis'][0]['key'])->toBe('revenue')
        ->and($dashboard['kpis'][0]['value'])->toBe(1500000)
        ->and($dashboard['breakdowns']['revenue_by_provider']['paystack'])->toBe(1000000)
        ->and($dashboard['breakdowns']['revenue_by_provider']['flutterwave'])->toBe(500000);
});

it('blocks non-admins from company dashboards', function (): void {
    $token = analyticsUser($this, 'plain@example.com');
    $this->withToken($token)->getJson('/api/v1/analytics/dashboards/executive')->assertStatus(403);
});

it('generates a financial report and exports it as CSV', function (): void {
    $token = analyticsAdmin($this, 'report@example.com');
    app(EventBus::class)->publish(new PaymentSucceeded('11111111-1111-4111-8111-111111111103', null, '22222222-2222-4222-8222-222222222201', 750000, 'NGN', 'mock'));

    $report = $this->withToken($token)->postJson('/api/v1/analytics/reports', ['key' => 'financial'])
        ->assertCreated()->json('data');
    expect($report['columns'])->toBe(['Metric', 'Amount (minor)']);
    expect($report['rows'][0])->toBe(['Revenue', 750000]);

    $response = $this->withToken($token)->get("/api/v1/analytics/reports/{$report['id']}/export?format=csv");
    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/csv');
    expect($response->streamedContent() ?: $response->getContent())->toContain('Revenue,750000');
});

it('creates and runs a scheduled report', function (): void {
    $token = analyticsAdmin($this, 'sched@example.com');
    app(EventBus::class)->publish(new PaymentSucceeded('11111111-1111-4111-8111-111111111104', null, '22222222-2222-4222-8222-222222222201', 200000, 'NGN', 'mock'));

    $this->withToken($token)->postJson('/api/v1/analytics/scheduled-reports', [
        'name' => 'Daily revenue',
        'report_key' => 'revenue',
        'cadence' => 'daily',
        'format' => 'csv',
        'recipients' => ['finance@example.com'],
    ])->assertCreated()->assertJsonPath('data.active', true);

    $this->withToken($token)->getJson('/api/v1/analytics/scheduled-reports')->assertOk()->assertJsonCount(1, 'data');
});
