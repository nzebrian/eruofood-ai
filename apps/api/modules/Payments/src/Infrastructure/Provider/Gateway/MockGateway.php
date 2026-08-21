<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Provider\Gateway;

use EruoFood\Payments\Application\DTO\GatewayCharge;
use EruoFood\Payments\Application\DTO\GatewayResult;
use EruoFood\Payments\Application\DTO\WebhookPayload;
use EruoFood\Payments\Application\Port\PaymentGateway;
use EruoFood\Payments\Application\Port\PayoutGateway;
use EruoFood\Payments\Domain\Enum\GatewayOutcome;
use EruoFood\Payments\Domain\Enum\PaymentProvider;
use EruoFood\Payments\Domain\ValueObject\BankAccount;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * A deterministic, offline payment gateway. It "captures" immediately and needs
 * no credentials, so the whole payment flow (initiate → capture → refund →
 * payout → webhook) runs in tests and local development without touching a real
 * provider. This is the default provider when APP_ENV=testing.
 */
final class MockGateway implements PaymentGateway, PayoutGateway
{
    /**
     * Outcomes queued for the next transfer(s), so a test can make the provider
     * time out or decline without a network.
     *
     * Static because the factory constructs a fresh MockGateway per call, so an
     * instance-level hook would be discarded before the code under test reached
     * it. Cleared by {@see reset()}, which the settlement tests call in their
     * `beforeEach`.
     *
     * @var list<GatewayOutcome>
     */
    private static array $transferOutcomes = [];

    /** @var array<string, GatewayOutcome> providerReference => status answer */
    private static array $statusAnswers = [];

    /**
     * How many times a transfer has actually been attempted.
     *
     * Exists so a test can assert the negative: that a kill switch stopped the
     * platform *before* it reached the provider, rather than after. The absence
     * of a payout row proves nothing was recorded; only this proves nothing was
     * sent. With a real gateway those two are the same question asked before and
     * after the money leaves.
     */
    private static int $transferCount = 0;

    /** Make the next transfer return $outcome instead of succeeding. */
    public static function nextTransfer(GatewayOutcome $outcome): void
    {
        self::$transferOutcomes[] = $outcome;
    }

    /** Make a later status query for $reference answer $outcome. */
    public static function answerStatus(string $reference, GatewayOutcome $outcome): void
    {
        self::$statusAnswers[$reference] = $outcome;
    }

    /** Transfers attempted since the last {@see reset()}. */
    public static function transferCount(): int
    {
        return self::$transferCount;
    }

    public static function reset(): void
    {
        self::$transferOutcomes = [];
        self::$statusAnswers = [];
        self::$transferCount = 0;
    }

    public function provider(): PaymentProvider
    {
        return PaymentProvider::Mock;
    }

    public function initialize(GatewayCharge $charge): GatewayResult
    {
        return new GatewayResult(
            success: true,
            providerReference: 'mock_'.$charge->reference,
            status: 'succeeded',
            authorizationUrl: null,
            message: 'Mock capture',
        );
    }

    public function verify(string $providerReference): GatewayResult
    {
        return new GatewayResult(true, $providerReference, 'succeeded');
    }

    public function refund(string $providerReference, Money $amount): GatewayResult
    {
        return new GatewayResult(true, $providerReference.'_rf', 'succeeded');
    }

    public function transfer(BankAccount $destination, Money $amount, string $reference): GatewayResult
    {
        self::$transferCount++;

        $outcome = array_shift(self::$transferOutcomes)
            ?? self::fromEnvironment('MOCK_GATEWAY_TRANSFER_OUTCOME')
            ?? GatewayOutcome::Succeeded;

        return GatewayResult::of($outcome, 'mock_tr_'.$reference, 'Mock transfer');
    }

    public function fetchTransferStatus(string $providerReference): GatewayResult
    {
        // Unqueried references answer `Succeeded`, matching what `transfer()`
        // does by default, so the happy path reconciles cleanly. A test that
        // cares makes the answer explicit.
        $outcome = self::$statusAnswers[$providerReference]
            ?? self::fromEnvironment('MOCK_GATEWAY_STATUS_OUTCOME')
            ?? GatewayOutcome::Succeeded;

        return GatewayResult::of($outcome, $providerReference, 'Mock transfer status');
    }

    /**
     * An outcome named by the environment, for cross-process tests.
     *
     * The static queues above cannot reach a worker running in its own OS
     * process, which is exactly where the concurrency harness needs a provider
     * that times out. Without this the harness had to force `unknown` with a
     * direct UPDATE *after* a successful transfer — manufacturing a state the
     * product cannot reach (ledger posted, run not succeeded) and then failing
     * the product for not matching it.
     *
     * Reads `getenv()` rather than Laravel's `env()`, which is cached and would
     * not see a variable exported for a child process.
     */
    private static function fromEnvironment(string $variable): ?GatewayOutcome
    {
        $value = getenv($variable);

        return is_string($value) && $value !== ''
            ? GatewayOutcome::tryFrom(strtolower(trim($value)))
            : null;
    }

    public function parseWebhook(string $rawBody, string $signature): WebhookPayload
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($rawBody, true) ?: [];

        return new WebhookPayload(
            eventId: (string) ($data['event_id'] ?? 'evt_'.md5($rawBody)),
            type: (string) ($data['type'] ?? 'payment.succeeded'),
            providerReference: (string) ($data['reference'] ?? ''),
            status: (string) ($data['status'] ?? 'succeeded'),
            amountMinor: (int) ($data['amount_minor'] ?? 0),
        );
    }
}
