<?php

declare(strict_types=1);

use EruoFood\Payments\Application\Port\PaymentGateway;
use EruoFood\Payments\Domain\Enum\GatewayOutcome;
use EruoFood\Payments\Domain\Enum\PaymentProvider;
use EruoFood\Payments\Domain\ValueObject\BankAccount;
use EruoFood\Payments\Infrastructure\Provider\GatewayFactory;
use EruoFood\Shared\Domain\ValueObject\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * What a real provider adapter reports when a bank transfer goes wrong.
 *
 * ## The defect this pins down
 *
 * Every HTTP adapter used to end `transfer()` with:
 *
 * ```php
 * return $this->result($res->successful(), $reference, $res->successful() ? 'processing' : 'failed');
 * ```
 *
 * so a 502, a timeout or a proxy's 403 became `failed` — and `failed` means the
 * provider declined, which makes a retry safe. It is not safe: the transfer may
 * have gone through. A thrown connection error was worse still, escaping
 * `transfer()` entirely and leaving the payout attempt with no recorded outcome.
 *
 * CI caught this because `.env.example` sets `APP_ENV=local`, which resolves
 * `payments.default` to a live provider, so the concurrency harness was calling
 * a real gateway. The harness now refuses to do that — and these tests make sure
 * the adapters would be correct even if it did.
 *
 * @param int|ConnectionException $response an HTTP status to return, or an exception to throw
 */
function gatewayFor(PaymentProvider $provider, int|ConnectionException $response): PaymentGateway
{
    // A closure rather than a list. `Http::fake([...])` reads a plain array as a
    // URL-pattern map, so a list of responses matches nothing and every request
    // falls through to a default empty 200 — which silently made the "accepted
    // transfer" case look broken and, more dangerously, could have made the
    // failure cases pass for the wrong reason.
    Http::fake(static fn (): mixed => $response instanceof ConnectionException
        ? throw $response
        : Http::response(['ok' => $response < 400], $response));

    return (new GatewayFactory(app(Illuminate\Http\Client\Factory::class), [
        'default' => $provider->value,
        'providers' => [$provider->value => [
            'enabled' => true,
            'base_url' => 'https://provider.test',
            'secret_key' => 'sk_test',
            'webhook_secret' => 'whsec_test',
        ]],
    ]))->for($provider);
}

/** Every HTTP-backed provider that can move money out. */
function httpPayoutProviders(): array
{
    return [
        PaymentProvider::Paystack,
        PaymentProvider::Flutterwave,
        PaymentProvider::Moniepoint,
        PaymentProvider::Stripe,
        PaymentProvider::Paypal,
    ];
}

function transferOnce(PaymentGateway $gateway): GatewayOutcome
{
    return $gateway->transfer(new BankAccount('Vendor Ltd', '0123456789', '058'), new Money(500_000), 'ref-1')
        ->outcome();
}

it('reports a provider 5xx as unknown, never as failed', function (): void {
    foreach (httpPayoutProviders() as $provider) {
        $outcome = transferOnce(gatewayFor($provider, 502));

        expect($outcome)->toBe(
            GatewayOutcome::Unknown,
            "{$provider->value}: a 502 on a transfer must be unknown, not a decline",
        )->and($outcome->isSafelyRetryable())->toBeFalse();
    }
});

it('reports a 4xx as unknown, because a bare status is not evidence the provider declined', function (): void {
    // A 403 can come from a proxy or a WAF and never reach the provider; a 409
    // frequently means "this reference already exists", i.e. the transfer *did*
    // happen. Neither is safe to read as a decline.
    foreach ([400, 401, 403, 404, 409, 422, 429] as $status) {
        $outcome = transferOnce(gatewayFor(PaymentProvider::Paystack, $status));

        expect($outcome)->toBe(GatewayOutcome::Unknown, "HTTP {$status} must be unknown")
            ->and($outcome->isSafelyRetryable())->toBeFalse();
    }
});

it('reports a connection failure as unknown instead of throwing out of transfer', function (): void {
    // The worst of the three: an escaping exception left the payout attempt in
    // `created` with the run stuck in `processing` — money possibly gone, and
    // nothing recorded to reconcile against.
    foreach (httpPayoutProviders() as $provider) {
        $gateway = gatewayFor($provider, new ConnectionException('cURL error 28: Operation timed out'));

        $outcome = transferOnce($gateway); // must not throw

        expect($outcome)->toBe(GatewayOutcome::Unknown, "{$provider->value}: a timeout must be unknown")
            ->and($outcome->requiresReconciliation())->toBeTrue();
    }
});

it('reports an accepted transfer as processing, awaiting confirmation', function (): void {
    foreach (httpPayoutProviders() as $provider) {
        $outcome = transferOnce(gatewayFor($provider, 200));

        // Processing, not Succeeded: the provider has the instruction and has
        // not finished. Posting a payout ledger entry now would claim money
        // left that may still be rejected.
        expect($outcome)->toBe(GatewayOutcome::Processing, $provider->value)
            ->and($outcome->isConfirmed())->toBeFalse();
    }
});

it('never lets a failed transfer look retryable on any HTTP provider', function (): void {
    // The single invariant behind all of the above, swept across every adapter
    // and every failure shape at once.
    $shapes = [500, 502, 503, 504, 408, 429, 400, 403, 409, new ConnectionException('reset by peer')];
    $checked = 0;

    foreach (httpPayoutProviders() as $provider) {
        foreach ($shapes as $shape) {
            $outcome = transferOnce(gatewayFor($provider, $shape));
            $checked++;

            expect($outcome->isSafelyRetryable())->toBeFalse(
                sprintf('%s must never mark a failed transfer retryable', $provider->value),
            );
        }
    }

    // The sweep is only meaningful if it actually swept something.
    expect($checked)->toBe(count(httpPayoutProviders()) * count($shapes));
});
