<?php

declare(strict_types=1);

use EruoFood\Shared\Infrastructure\Idempotency\Model\IdempotencyKeyModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Recovery after a client loses the connection mid-operation.
 *
 * The property under test is not "the endpoint returns data". It is that a
 * client which cannot know what happened is never told something that would
 * make it act wrongly — resend a payment that went through, or report a failure
 * that did not occur.
 */

/** @return array{token: string, id: string} */
function reconUser(object $test, string $email): array
{
    Mail::fake();

    $data = $test->postJson('/api/v1/auth/register', [
        'name' => 'Test Person',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data');

    return ['token' => $data['tokens']['access_token'], 'id' => $data['user']['id']];
}

/** Record an idempotency claim exactly as M23's store would. */
function reconClaim(string $userId, string $scope, string $key, string $state, ?array $snapshot = null): void
{
    IdempotencyKeyModel::query()->create([
        'id' => (string) Str::uuid(),
        'scope' => $scope,
        'idempotency_key' => $key,
        'request_hash' => hash('sha256', $key),
        'user_id' => $userId,
        'state' => $state,
        'response_snapshot' => $snapshot,
        'created_at' => now(),
        'completed_at' => $state === IdempotencyKeyModel::STATE_COMPLETED ? now() : null,
        'expires_at' => now()->addDay(),
    ]);
}

// --------------------------------------------------------------- the answers

it('reports an operation the server never received as safe to resend', function (): void {
    // Nothing took effect, so a resend is correct — and saying so explicitly is
    // what stops a client inventing a failure to show a customer.
    ['token' => $token] = reconUser($this, 'recon1@example.com');

    $this->withToken($token)
        ->postJson('/api/v1/reconcile', [
            'operations' => [['scope' => 'payments.charge', 'key' => 'never-sent']],
        ])
        ->assertOk()
        ->assertJsonPath('data.operations.0.outcome', 'never_received')
        ->assertJsonPath('data.operations.0.safe_to_resend', true);
});

it('refuses to call an in-flight operation safe to resend', function (): void {
    // The crash-mid-payment case: a claim exists with no result. Resending is
    // how the customer gets charged twice.
    ['token' => $token, 'id' => $userId] = reconUser($this, 'recon2@example.com');
    reconClaim($userId, 'payments.charge', 'in-flight-key', IdempotencyKeyModel::STATE_IN_PROGRESS);

    $this->withToken($token)
        ->postJson('/api/v1/reconcile', [
            'operations' => [['scope' => 'payments.charge', 'key' => 'in-flight-key']],
        ])
        ->assertOk()
        ->assertJsonPath('data.operations.0.outcome', 'in_progress')
        ->assertJsonPath('data.operations.0.phase', 'processing')
        ->assertJsonPath('data.operations.0.safe_to_resend', false);
});

it('reports a completed operation with its settled phase', function (): void {
    ['token' => $token, 'id' => $userId] = reconUser($this, 'recon3@example.com');
    reconClaim($userId, 'payments.charge', 'done-key', IdempotencyKeyModel::STATE_COMPLETED, [
        'phase' => 'confirmed',
        'status' => 'succeeded',
        'resource_type' => 'payment',
        'id' => 'pay-123',
    ]);

    $this->withToken($token)
        ->postJson('/api/v1/reconcile', [
            'operations' => [['scope' => 'payments.charge', 'key' => 'done-key']],
        ])
        ->assertOk()
        ->assertJsonPath('data.operations.0.outcome', 'settled')
        ->assertJsonPath('data.operations.0.phase', 'confirmed')
        ->assertJsonPath('data.operations.0.status', 'succeeded')
        ->assertJsonPath('data.operations.0.resource_id', 'pay-123')
        ->assertJsonPath('data.operations.0.safe_to_resend', false);
});

// ------------------------------------------------------------------ security

it('never reveals another account\'s operation', function (): void {
    // An idempotency key is a client-chosen string. Answering on the key alone
    // would let anyone enumerate keys and read other people's payment outcomes.
    ['id' => $ownerId] = reconUser($this, 'owner@example.com');
    ['token' => $intruderToken] = reconUser($this, 'intruder@example.com');

    reconClaim($ownerId, 'payments.charge', 'someone-elses-key', IdempotencyKeyModel::STATE_COMPLETED, [
        'phase' => 'confirmed',
        'status' => 'succeeded',
        'id' => 'pay-secret',
    ]);

    $response = $this->withToken($intruderToken)
        ->postJson('/api/v1/reconcile', [
            'operations' => [['scope' => 'payments.charge', 'key' => 'someone-elses-key']],
        ])
        ->assertOk();

    // Answered exactly as a key that never existed — distinguishing the two
    // would confirm which keys are real.
    expect($response->json('data.operations.0.outcome'))->toBe('never_received')
        ->and($response->json('data.operations.0.status'))->toBeNull()
        ->and($response->json('data.operations.0.resource_id'))->toBeNull();

    // And nothing of the owner's leaked into the body at all.
    expect($response->getContent())->not->toContain('pay-secret')
        ->and($response->getContent())->not->toContain('succeeded');
});

it('refuses an unauthenticated caller', function (): void {
    $this->postJson('/api/v1/reconcile', [
        'operations' => [['scope' => 'payments.charge', 'key' => 'k']],
    ])->assertUnauthorized();
});

it('bounds how many operations one call may reconcile', function (): void {
    ['token' => $token] = reconUser($this, 'recon4@example.com');

    $operations = array_map(
        static fn (int $i): array => ['scope' => 'payments.charge', 'key' => "k{$i}"],
        range(1, 51),
    );

    $this->withToken($token)
        ->postJson('/api/v1/reconcile', ['operations' => $operations])
        ->assertStatus(422)
        ->assertJsonValidationErrors('operations');
});

// ------------------------------------------------------------ server clock

it('returns server time so a client does not trust its own clock', function (): void {
    // A device clock may be wrong or deliberately altered, and it must never be
    // authoritative for anything the server decides.
    ['token' => $token] = reconUser($this, 'recon5@example.com');

    $response = $this->withToken($token)
        ->postJson('/api/v1/reconcile', [
            'operations' => [['scope' => 'payments.charge', 'key' => 'k']],
        ])
        ->assertOk();

    expect($response->json('data.server_time'))->toBeString()->not->toBeEmpty();
});

it('reconciles a batch in one call', function (): void {
    // A client that was offline has a queue, not one request; making it issue
    // six round trips on a connection that just came back is how reconciliation
    // becomes the thing that fails.
    ['token' => $token, 'id' => $userId] = reconUser($this, 'recon6@example.com');

    reconClaim($userId, 'payments.charge', 'batch-done', IdempotencyKeyModel::STATE_COMPLETED, ['phase' => 'confirmed']);
    reconClaim($userId, 'orders.place', 'batch-flight', IdempotencyKeyModel::STATE_IN_PROGRESS);

    $response = $this->withToken($token)
        ->postJson('/api/v1/reconcile', [
            'operations' => [
                ['scope' => 'payments.charge', 'key' => 'batch-done'],
                ['scope' => 'orders.place', 'key' => 'batch-flight'],
                ['scope' => 'orders.place', 'key' => 'batch-unknown'],
            ],
        ])
        ->assertOk();

    expect($response->json('data.operations'))->toHaveCount(3)
        ->and($response->json('data.operations.0.outcome'))->toBe('settled')
        ->and($response->json('data.operations.1.outcome'))->toBe('in_progress')
        ->and($response->json('data.operations.2.outcome'))->toBe('never_received');
});

it('does not change any state', function (): void {
    // A recovery endpoint that mutates can make an outage worse than the crash.
    ['token' => $token, 'id' => $userId] = reconUser($this, 'recon7@example.com');
    reconClaim($userId, 'payments.charge', 'untouched', IdempotencyKeyModel::STATE_IN_PROGRESS);

    $before = IdempotencyKeyModel::query()->where('idempotency_key', 'untouched')->firstOrFail();

    $this->withToken($token)->postJson('/api/v1/reconcile', [
        'operations' => [['scope' => 'payments.charge', 'key' => 'untouched']],
    ])->assertOk();

    $after = IdempotencyKeyModel::query()->where('idempotency_key', 'untouched')->firstOrFail();

    expect($after->state)->toBe($before->state)
        ->and($after->completed_at)->toBe($before->completed_at);
});
