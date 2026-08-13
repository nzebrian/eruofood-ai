<?php

declare(strict_types=1);

namespace EruoFood\Payments\Application\Service;

use EruoFood\Payments\Application\Port\PaymentGatewayFactory;
use EruoFood\Payments\Domain\Enum\PaymentProvider;
use EruoFood\Payments\Domain\Webhook\WebhookEventRepository;
use EruoFood\Shared\Domain\TransactionManager;

/**
 * Processes inbound provider webhooks exactly-once. It verifies the signature
 * and parses the payload through the provider adapter, dedups on the provider
 * event id (idempotency), then routes the normalised outcome to the payment
 * pipeline. Returns whether the event was newly applied.
 *
 * Claiming the event and applying it share one transaction, and the claim comes
 * first. That ordering is what makes "exactly once" true under concurrency: a
 * duplicate delivery arriving mid-flight loses the race on the unique
 * `(provider, event_id)` index instead of slipping through a check-then-act
 * window and capturing the payment a second time.
 *
 * Rolling the claim back with the work is deliberate too — if applying the
 * outcome fails, the event is left unclaimed so the provider's retry can
 * legitimately reprocess it.
 */
final readonly class WebhookService
{
    public function __construct(
        private PaymentGatewayFactory $gateways,
        private WebhookEventRepository $seen,
        private PaymentService $payments,
        private TransactionManager $transactions,
    ) {
    }

    public function handle(string $providerName, string $rawBody, string $signature): bool
    {
        $provider = PaymentProvider::from($providerName);

        // Signature verification and parsing happen outside the transaction:
        // they touch no state and an invalid signature must not open one.
        $payload = $this->gateways->for($provider)->parseWebhook($rawBody, $signature);

        [$applied, $payment] = $this->transactions->atomic(function () use ($providerName, $payload): array {
            if (! $this->seen->claim($providerName, $payload->eventId, $payload->type)) {
                return [false, null]; // duplicate delivery — already processed
            }

            $payment = null;
            if (in_array($payload->type, ['payment.succeeded', 'payment.failed'], true)) {
                $status = $payload->type === 'payment.succeeded' ? 'succeeded' : 'failed';
                $payment = $this->payments->applyOutcome($providerName, $payload->providerReference, $status);
            }

            return [true, $payment];
        });

        if ($payment !== null) {
            $this->payments->announce($payment);
        }

        return $applied;
    }
}
