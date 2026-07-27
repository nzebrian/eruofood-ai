<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Provider\Gateway;

use EruoFood\Payments\Application\DTO\GatewayCharge;
use EruoFood\Payments\Application\DTO\GatewayResult;
use EruoFood\Payments\Application\DTO\WebhookPayload;
use EruoFood\Payments\Domain\Enum\PaymentProvider;
use EruoFood\Payments\Domain\Exception\PaymentsInvalidState;
use EruoFood\Payments\Domain\ValueObject\BankAccount;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * Stripe adapter — architecture-ready (disabled by default). Uses PaymentIntents;
 * webhooks are verified with the Stripe-Signature scheme (simplified here to the
 * shared HMAC helper).
 */
final class StripeGateway extends AbstractHttpGateway
{
    public function provider(): PaymentProvider
    {
        return PaymentProvider::Stripe;
    }

    public function initialize(GatewayCharge $charge): GatewayResult
    {
        $res = $this->client()->asForm()->post('/payment_intents', [
            'amount' => $charge->amount->minorUnits,
            'currency' => strtolower($charge->amount->currency),
            'metadata[reference]' => $charge->reference,
            'receipt_email' => $charge->customerEmail,
        ]);
        if (! $res->successful()) {
            return $this->result(false, $charge->reference, 'failed');
        }

        return $this->result(true, (string) $res->json('id'), 'processing');
    }

    public function verify(string $providerReference): GatewayResult
    {
        $res = $this->client()->get('/payment_intents/'.$providerReference);
        $ok = (string) $res->json('status') === 'succeeded';

        return $this->result($ok, $providerReference, $ok ? 'succeeded' : 'failed');
    }

    public function refund(string $providerReference, Money $amount): GatewayResult
    {
        $res = $this->client()->asForm()->post('/refunds', ['payment_intent' => $providerReference, 'amount' => $amount->minorUnits]);

        return $this->result($res->successful(), $providerReference.'_rf', $res->successful() ? 'succeeded' : 'failed');
    }

    public function transfer(BankAccount $destination, Money $amount, string $reference): GatewayResult
    {
        $res = $this->client()->asForm()->post('/payouts', ['amount' => $amount->minorUnits, 'currency' => strtolower($amount->currency)]);

        return $this->result($res->successful(), $reference, $res->successful() ? 'processing' : 'failed');
    }

    public function parseWebhook(string $rawBody, string $signature): WebhookPayload
    {
        if (! $this->hmacMatches($rawBody, $signature, 'sha256')) {
            throw new PaymentsInvalidState('Invalid Stripe webhook signature.');
        }
        /** @var array<string, mixed> $body */
        $body = json_decode($rawBody, true) ?: [];
        $type = (string) ($body['type'] ?? '');
        $object = (array) ($body['data']['object'] ?? []);
        $ok = $type === 'payment_intent.succeeded';

        return new WebhookPayload(
            eventId: (string) ($body['id'] ?? md5($rawBody)),
            type: $ok ? 'payment.succeeded' : 'payment.failed',
            providerReference: (string) ($object['id'] ?? ''),
            status: $ok ? 'succeeded' : 'failed',
            amountMinor: (int) ($object['amount'] ?? 0),
        );
    }
}
