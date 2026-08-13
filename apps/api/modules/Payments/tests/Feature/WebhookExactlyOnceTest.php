<?php

declare(strict_types=1);

use EruoFood\Payments\Application\Service\LedgerIntegrityService;
use EruoFood\Payments\Domain\Webhook\WebhookEventRepository;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\LedgerEntryModel;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\PaymentModel;
use EruoFood\Payments\Infrastructure\Persistence\Eloquent\Model\WebhookEventModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * M23 — a repeated webhook delivery must capture the payment exactly once.
 *
 * The old flow asked "have I seen this event?", applied the outcome, and only
 * then recorded the event. Two deliveries arriving together both passed the
 * check before either recorded, and the payment was captured — and posted to the
 * ledger — twice. The event is now *claimed* by an insert against a unique index
 * before any work happens, inside the same transaction as the work.
 */
function pendingMockPayment(object $test, string $email): string
{
    Mail::fake();
    $token = $test->postJson('/api/v1/auth/register', [
        'name' => 'Hook Payer',
        'email' => $email,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
    ])->assertCreated()->json('data.tokens.access_token');

    return $test->withToken($token)->postJson('/api/v1/payments/payments', [
        'amount_minor' => 500_000,
        'customer_email' => $email,
        'method_type' => 'card',
        'provider' => 'mock',
    ])->assertCreated()->json('data.reference');
}

function deliverWebhook(object $test, string $eventId, string $reference): Illuminate\Testing\TestResponse
{
    $body = json_encode([
        'event_id' => $eventId,
        'type' => 'payment.succeeded',
        'reference' => $reference,
        'status' => 'succeeded',
        'amount_minor' => 500_000,
    ], JSON_THROW_ON_ERROR);

    return $test->call('POST', '/api/v1/payments/webhooks/mock', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);
}

it('claims the event before doing the work, so a repeat is a no-op', function (): void {
    $reference = pendingMockPayment($this, 'hook-once@example.com');

    deliverWebhook($this, 'evt_once_1', $reference)->assertOk()->assertJson(['applied' => true]);

    $ledgerAfterFirst = LedgerEntryModel::query()->count();

    // Five more deliveries of the same event — providers retry aggressively.
    foreach (range(1, 5) as $ignored) {
        deliverWebhook($this, 'evt_once_1', $reference)->assertOk()->assertJson(['applied' => false]);
    }

    expect(LedgerEntryModel::query()->count())->toBe($ledgerAfterFirst)
        ->and(WebhookEventModel::query()->where('event_id', 'evt_once_1')->count())->toBe(1)
        ->and(app(LedgerIntegrityService::class)->verify()->isBalanced())->toBeTrue();
});

it('rolls the claim back when applying the outcome fails, so the provider retry still works', function (): void {
    $reference = pendingMockPayment($this, 'hook-retry@example.com');

    $body = json_encode([
        'event_id' => 'evt_retry_1',
        'type' => 'payment.succeeded',
        'reference' => $reference,
        'status' => 'succeeded',
        'amount_minor' => 500_000,
    ], JSON_THROW_ON_ERROR);

    // Drive the service directly: this test is about what the transaction left
    // behind, not about how an HTTP error is rendered.
    $real = app(WebhookEventRepository::class);
    $flaky = Mockery::mock(WebhookEventRepository::class);
    $flaky->shouldReceive('claim')->andReturnUsing(
        static function (string $provider, string $eventId, string $type) use ($real): bool {
            // Claim for real, then die — the exact shape of a crash between
            // claiming the event and finishing the work.
            $real->claim($provider, $eventId, $type);

            throw new RuntimeException('database went away mid-apply');
        },
    );
    app()->instance(WebhookEventRepository::class, $flaky);

    $failing = app(EruoFood\Payments\Application\Service\WebhookService::class);
    expect(fn (): bool => $failing->handle('mock', $body, ''))->toThrow(RuntimeException::class);

    // The claim must not survive the rollback — otherwise the provider's retry
    // would be dismissed as a duplicate and the payment would never capture.
    app()->instance(WebhookEventRepository::class, $real);
    expect(WebhookEventModel::query()->where('event_id', 'evt_retry_1')->count())->toBe(0);

    $healthy = app(EruoFood\Payments\Application\Service\WebhookService::class);
    expect($healthy->handle('mock', $body, ''))->toBeTrue()
        ->and(WebhookEventModel::query()->where('event_id', 'evt_retry_1')->count())->toBe(1);
});

it('treats distinct events on the same payment independently', function (): void {
    $reference = pendingMockPayment($this, 'hook-distinct@example.com');

    deliverWebhook($this, 'evt_a', $reference)->assertOk()->assertJson(['applied' => true]);
    // A different event id is applied, but capture itself is idempotent, so the
    // payment is not captured a second time.
    deliverWebhook($this, 'evt_b', $reference)->assertOk()->assertJson(['applied' => true]);

    $captured = PaymentModel::query()->where('reference', $reference)->value('status');

    expect($captured)->toBe('succeeded')
        ->and(WebhookEventModel::query()->count())->toBe(2)
        ->and(app(LedgerIntegrityService::class)->verify()->isBalanced())->toBeTrue();
});
