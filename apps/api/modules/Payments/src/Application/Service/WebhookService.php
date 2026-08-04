<?php

declare(strict_types=1);

namespace EruoFood\Payments\Application\Service;

use EruoFood\Payments\Application\Port\PaymentGatewayFactory;
use EruoFood\Payments\Domain\Enum\PaymentProvider;
use EruoFood\Payments\Domain\Webhook\WebhookEventRepository;

/**
 * Processes inbound provider webhooks exactly-once. It verifies the signature
 * and parses the payload through the provider adapter, dedups on the provider
 * event id (idempotency), then routes the normalised outcome to the payment
 * pipeline. Returns whether the event was newly applied.
 */
final readonly class WebhookService
{
    public function __construct(
        private PaymentGatewayFactory $gateways,
        private WebhookEventRepository $seen,
        private PaymentService $payments,
    ) {
    }

    public function handle(string $providerName, string $rawBody, string $signature): bool
    {
        $provider = PaymentProvider::from($providerName);
        $payload = $this->gateways->for($provider)->parseWebhook($rawBody, $signature);

        if ($this->seen->seen($providerName, $payload->eventId)) {
            return false; // duplicate delivery — already processed
        }

        if (in_array($payload->type, ['payment.succeeded', 'payment.failed'], true)) {
            $status = $payload->type === 'payment.succeeded' ? 'succeeded' : 'failed';
            $this->payments->applyOutcome($providerName, $payload->providerReference, $status);
        }

        $this->seen->record($providerName, $payload->eventId, $payload->type);

        return true;
    }
}
