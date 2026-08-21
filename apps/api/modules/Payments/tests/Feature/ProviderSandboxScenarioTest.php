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
 * M28 Phase 7 — every way a payment provider can answer, and what we conclude.
 *
 * ## The one invariant
 *
 *   ambiguous outcome → UNKNOWN → no blind retry → reconciliation → authoritative result
 *
 * Everything below is a way of testing that sentence. The interesting half is
 * not that a success reads as success; it is that of the fourteen scenarios,
 * exactly **two** are ever allowed to conclude anything on their own — a 2xx
 * acceptance (which concludes only "the provider has it") and a provider-
 * authoritative status query. Every other shape resolves to UNKNOWN and waits.
 *
 * ## Where the line sits, and why it is drawn there
 *
 * `transfer()` may never return {@see GatewayOutcome::Failed}. A transport-level
 * answer is not evidence about what a bank did: a 403 may come from a WAF the
 * request never got past, a 409 usually means the transfer already exists, and a
 * timeout means we have no idea. Only {@see PayoutGateway::fetchTransferStatus()},
 * which asks the provider about the reference directly, may conclude that a
 * transfer did not happen — and `Failed` is the only outcome
 * {@see GatewayOutcome::isSafelyRetryable()} permits.
 *
 * That asymmetry is the whole design. Reading a transport failure as a decline
 * is how a merchant gets paid twice.
 *
 * ## Scenarios covered elsewhere, deliberately not duplicated
 *
 * Duplicate webhook delivery is `WebhookExactlyOnceTest` and `WebhookTest`;
 * idempotency-key collision is `IdempotencyStoreTest` and
 * `IdempotencyCoverageTest`; concurrent duplicate execution under real OS
 * contention is scenario 13 of `scripts/financial_concurrency_validation.php`.
 * Repeating them here would give the appearance of coverage without adding any.
 */

/** @param int|ConnectionException $transferResponse status to return, or exception to throw */
function sandboxGateway(
    PaymentProvider $provider,
    int|ConnectionException $transferResponse,
    ?array $statusBody = null,
    int $statusCode = 200,
): PaymentGateway {
    // A *fresh* client factory per gateway, never the container's shared one.
    //
    // Illuminate's fake registers stubs cumulatively and the first match wins,
    // so calling Http::fake() a second time inside one test silently leaves the
    // first stub in charge. A loop that builds five gateways that way exercises
    // one adapter five times and reports it as five — a sweep that passes
    // without sweeping. Isolating the factory makes each gateway genuinely its
    // own.
    $http = new Illuminate\Http\Client\Factory();

    $http->fake(static function ($request) use ($transferResponse, $statusBody, $statusCode): mixed {
        // The status query is a GET; the transfer is a POST. Separating them by
        // method rather than URL keeps this working across five providers whose
        // paths differ.
        if ($request->method() === 'GET') {
            return Http::response($statusBody ?? [], $statusCode);
        }

        return $transferResponse instanceof ConnectionException
            ? throw $transferResponse
            : Http::response(['ok' => $transferResponse < 400], $transferResponse);
    });

    return (new GatewayFactory($http, [
        'default' => $provider->value,
        'providers' => [$provider->value => [
            'enabled' => true,
            'base_url' => 'https://provider.test',
            'secret_key' => 'sk_test',
            'webhook_secret' => 'whsec_test',
            'transfer_status_path' => '/transfer/{reference}',
        ]],
    ]))->for($provider);
}

function sandboxTransfer(PaymentGateway $gateway): GatewayOutcome
{
    return $gateway->transfer(
        new BankAccount('Vendor Ltd', '0123456789', '058'),
        new Money(500_000),
        'sandbox-ref-1',
    )->outcome();
}

/** Every HTTP-backed provider that can move money out. */
function sandboxProviders(): array
{
    return [
        PaymentProvider::Paystack,
        PaymentProvider::Flutterwave,
        PaymentProvider::Moniepoint,
        PaymentProvider::Stripe,
        PaymentProvider::Paypal,
    ];
}

describe('1. confirmed success — only the provider may say so', function (): void {
    it('concludes success only from an authoritative status query', function (): void {
        foreach (sandboxProviders() as $provider) {
            $gateway = sandboxGateway($provider, 200, ['data' => ['status' => 'success']]);

            // The transfer itself concludes nothing beyond "accepted".
            expect(sandboxTransfer($gateway))->toBe(GatewayOutcome::Processing, $provider->value);

            $status = $gateway->fetchTransferStatus('sandbox-ref-1')->outcome();

            expect($status)->toBe(GatewayOutcome::Succeeded, $provider->value)
                ->and($status->isConfirmed())->toBeTrue()
                ->and($status->requiresReconciliation())->toBeFalse();
        }
    });

    it('accepts every spelling of success a provider might use', function (string $spelling): void {
        $gateway = sandboxGateway(PaymentProvider::Paystack, 200, ['data' => ['status' => $spelling]]);

        expect($gateway->fetchTransferStatus('sandbox-ref-1')->outcome())->toBe(GatewayOutcome::Succeeded);
    })->with(['success', 'successful', 'succeeded', 'completed', 'paid', 'SUCCESS', ' Success ']);
});

describe('2. explicit provider rejection — the only retryable outcome', function (): void {
    it('reads a provider-confirmed decline as failed, and only from a status query', function (): void {
        foreach (sandboxProviders() as $provider) {
            $gateway = sandboxGateway($provider, 200, ['data' => ['status' => 'declined']]);
            $status = $gateway->fetchTransferStatus('sandbox-ref-1')->outcome();

            expect($status)->toBe(GatewayOutcome::Failed, $provider->value)
                ->and($status->isSafelyRetryable())->toBeTrue();
        }
    });

    it('accepts every spelling of rejection a provider might use', function (string $spelling): void {
        $gateway = sandboxGateway(PaymentProvider::Paystack, 200, ['data' => ['status' => $spelling]]);

        expect($gateway->fetchTransferStatus('sandbox-ref-1')->outcome())->toBe(GatewayOutcome::Failed);
    })->with(['failed', 'reversed', 'declined', 'rejected', 'abandoned']);

    it('never lets transfer() itself return a retryable failure, on any provider or status', function (): void {
        // The asymmetry, stated as a sweep: no transport answer may conclude
        // that the bank declined.
        $checked = 0;

        foreach (sandboxProviders() as $provider) {
            foreach ([400, 401, 402, 403, 404, 409, 422, 429, 500, 502, 503, 504] as $status) {
                $outcome = sandboxTransfer(sandboxGateway($provider, $status));
                $checked++;

                expect($outcome)->not->toBe(
                    GatewayOutcome::Failed,
                    sprintf('%s concluded a decline from HTTP %d', $provider->value, $status),
                )->and($outcome->isSafelyRetryable())->toBeFalse();
            }
        }

        expect($checked)->toBe(count(sandboxProviders()) * 12);
    });
});

describe('3 & 4. timeout and connection failure', function (): void {
    it('resolves a transport timeout to unknown without throwing', function (): void {
        foreach (sandboxProviders() as $provider) {
            $gateway = sandboxGateway($provider, new ConnectionException('cURL error 28: Operation timed out'));

            $outcome = sandboxTransfer($gateway);

            expect($outcome)->toBe(GatewayOutcome::Unknown, $provider->value)
                ->and($outcome->requiresReconciliation())->toBeTrue();
        }
    });

    it('resolves an HTTP 408 to unknown as well', function (): void {
        expect(sandboxTransfer(sandboxGateway(PaymentProvider::Paystack, 408)))->toBe(GatewayOutcome::Unknown);
    });

    it('resolves a timeout on the status query to unknown rather than to a decline', function (): void {
        // A reconciler that cannot reach the provider has learned nothing. If
        // this returned Failed, the sweep would "resolve" an unknown payout
        // into a retryable one without any provider ever having spoken.
        $http = new Illuminate\Http\Client\Factory();
        $http->fake(static fn (): mixed => throw new ConnectionException('timed out'));

        $gateway = (new GatewayFactory($http, [
            'default' => 'paystack',
            'providers' => ['paystack' => [
                'enabled' => true,
                'base_url' => 'https://provider.test',
                'secret_key' => 'sk_test',
                'transfer_status_path' => '/transfer/{reference}',
            ]],
        ]))->for(PaymentProvider::Paystack);

        expect($gateway->fetchTransferStatus('sandbox-ref-1')->outcome())->toBe(GatewayOutcome::Unknown);
    });
});

describe('5 & 6. 4xx and 5xx responses', function (): void {
    it('resolves every 4xx to unknown, because a bare status is not evidence', function (int $status): void {
        foreach (sandboxProviders() as $provider) {
            expect(sandboxTransfer(sandboxGateway($provider, $status)))
                ->toBe(GatewayOutcome::Unknown, "{$provider->value} on HTTP {$status}");
        }
    })->with([400, 401, 403, 404, 409, 422, 429]);

    it('resolves every 5xx to unknown', function (int $status): void {
        foreach (sandboxProviders() as $provider) {
            expect(sandboxTransfer(sandboxGateway($provider, $status)))
                ->toBe(GatewayOutcome::Unknown, "{$provider->value} on HTTP {$status}");
        }
    })->with([500, 502, 503, 504]);

    it('resolves a 4xx on the status query to unknown', function (): void {
        $gateway = sandboxGateway(PaymentProvider::Paystack, 200, ['error' => 'not found'], 404);

        expect($gateway->fetchTransferStatus('sandbox-ref-1')->outcome())->toBe(GatewayOutcome::Unknown);
    });
});

describe('7 & 8. malformed and ambiguous responses', function (): void {
    it('never concludes anything from a status body it cannot read', function (mixed $body): void {
        $gateway = sandboxGateway(PaymentProvider::Paystack, 200, $body);

        expect($gateway->fetchTransferStatus('sandbox-ref-1')->outcome())->toBe(GatewayOutcome::Unknown);
    })->with([
        'empty body' => [[]],
        'no status field' => [['data' => ['amount' => 500000]]],
        'null status' => [['data' => ['status' => null]]],
        'empty status' => [['data' => ['status' => '']]],
        'unrecognised status' => [['data' => ['status' => 'in_limbo']]],
        'wrong shape' => [['data' => 'not-an-object']],
        'provider vocabulary we do not know' => [['data' => ['status' => 'partially_reversed']]],
    ]);

    it('treats an accepted-but-unreadable transfer as processing, never as confirmed', function (): void {
        // A 2xx with a body we cannot parse still means the provider took the
        // instruction. Processing is the honest reading: not concluded, not
        // retryable, and it still has to be reconciled.
        foreach (sandboxProviders() as $provider) {
            $outcome = sandboxTransfer(sandboxGateway($provider, 200));

            expect($outcome)->toBe(GatewayOutcome::Processing, $provider->value)
                ->and($outcome->isConfirmed())->toBeFalse()
                ->and($outcome->isSafelyRetryable())->toBeFalse();
        }
    });
});

describe('9. a delayed provider', function (): void {
    it('leaves a still-working transfer as processing, concluding nothing', function (string $spelling): void {
        $gateway = sandboxGateway(PaymentProvider::Paystack, 200, ['data' => ['status' => $spelling]]);
        $outcome = $gateway->fetchTransferStatus('sandbox-ref-1')->outcome();

        expect($outcome)->toBe(GatewayOutcome::Processing)
            ->and($outcome->isConfirmed())->toBeFalse()
            ->and($outcome->isSafelyRetryable())->toBeFalse();
    })->with(['pending', 'processing', 'queued', 'new']);

    it('does not let a slow provider become a failed one', function (): void {
        // The sweep asks again next time. A reconciler that gave up after N
        // attempts and wrote `failed` would be inventing an answer.
        $gateway = sandboxGateway(PaymentProvider::Paystack, 200, ['data' => ['status' => 'pending']]);

        foreach (range(1, 5) as $sweep) {
            expect($gateway->fetchTransferStatus('sandbox-ref-1')->outcome())
                ->toBe(GatewayOutcome::Processing, "sweep {$sweep}");
        }
    });
});

describe('13 & 14. status reconciliation resolves an unknown', function (): void {
    it('refuses to answer at all when no status path is configured', function (): void {
        // An adapter that cannot ask must say so, not guess. Without this, a
        // provider missing its status path would silently resolve every unknown
        // payout to whatever the default happened to be.
        $http = new Illuminate\Http\Client\Factory();
        $http->fake();

        $gateway = (new GatewayFactory($http, [
            'default' => 'paystack',
            'providers' => ['paystack' => [
                'enabled' => true,
                'base_url' => 'https://provider.test',
                'secret_key' => 'sk_test',
                // transfer_status_path deliberately absent
            ]],
        ]))->for(PaymentProvider::Paystack);

        $result = $gateway->fetchTransferStatus('sandbox-ref-1');

        expect($result->outcome())->toBe(GatewayOutcome::Unknown)
            ->and($result->outcome()->requiresReconciliation())->toBeTrue();
    });

    it('turns an unknown transfer into a definite answer, both ways', function (string $providerStatus, GatewayOutcome $expected): void {
        // Scenario 14 end to end at the adapter: the transfer times out
        // (unknown), then the provider is asked and gives the real answer.
        foreach (sandboxProviders() as $provider) {
            // One gateway, one fake: the transfer times out, and the later
            // status query for the same reference is what settles it. Two
            // gateways would be two fakes, and the first would answer both.
            $gateway = sandboxGateway(
                $provider,
                new ConnectionException('timed out'),
                ['data' => ['status' => $providerStatus]],
            );

            expect(sandboxTransfer($gateway))->toBe(GatewayOutcome::Unknown, $provider->value)
                ->and($gateway->fetchTransferStatus('sandbox-ref-1')->outcome())
                ->toBe($expected, $provider->value);
        }
    })->with([
        'the money did leave' => ['success', GatewayOutcome::Succeeded],
        'the money did not leave' => ['failed', GatewayOutcome::Failed],
    ]);
});

it('permits exactly one outcome to be retried, across every scenario in this file', function (): void {
    // The invariant the whole milestone rests on, asserted once over the enum
    // rather than inferred from the cases above.
    $retryable = array_values(array_filter(
        GatewayOutcome::cases(),
        static fn (GatewayOutcome $o): bool => $o->isSafelyRetryable(),
    ));

    expect($retryable)->toBe([GatewayOutcome::Failed])
        ->and(GatewayOutcome::Unknown->requiresReconciliation())->toBeTrue()
        ->and(GatewayOutcome::Unknown->isSafelyRetryable())->toBeFalse();
});
