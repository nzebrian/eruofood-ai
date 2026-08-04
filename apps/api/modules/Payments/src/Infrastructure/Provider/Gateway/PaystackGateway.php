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

/** Paystack adapter (initialize/verify/refund/transfer + HMAC-SHA512 webhooks). */
final class PaystackGateway extends AbstractHttpGateway
{
    public function provider(): PaymentProvider
    {
        return PaymentProvider::Paystack;
    }

    public function initialize(GatewayCharge $charge): GatewayResult
    {
        $res = $this->client()->post('/transaction/initialize', [
            'reference' => $charge->reference,
            'amount' => $charge->amount->minorUnits,
            'email' => $charge->customerEmail,
            'currency' => $charge->amount->currency,
            'metadata' => $charge->metadata,
        ]);
        if (! $res->successful()) {
            return $this->result(false, $charge->reference, 'failed');
        }
        $data = (array) $res->json('data');

        return $this->result(true, (string) ($data['reference'] ?? $charge->reference), 'processing', (string) ($data['authorization_url'] ?? ''));
    }

    public function verify(string $providerReference): GatewayResult
    {
        $res = $this->client()->get('/transaction/verify/'.$providerReference);
        $status = (string) $res->json('data.status');

        return $this->result($status === 'success', $providerReference, $status === 'success' ? 'succeeded' : 'failed');
    }

    public function refund(string $providerReference, Money $amount): GatewayResult
    {
        $res = $this->client()->post('/refund', ['transaction' => $providerReference, 'amount' => $amount->minorUnits]);

        return $this->result($res->successful(), $providerReference.'_rf', $res->successful() ? 'succeeded' : 'failed');
    }

    public function transfer(BankAccount $destination, Money $amount, string $reference): GatewayResult
    {
        $res = $this->client()->post('/transfer', [
            'source' => 'balance',
            'amount' => $amount->minorUnits,
            'reference' => $reference,
            'recipient' => $destination->accountNumber,
        ]);

        return $this->result($res->successful(), $reference, $res->successful() ? 'processing' : 'failed');
    }

    public function parseWebhook(string $rawBody, string $signature): WebhookPayload
    {
        if (! $this->hmacMatches($rawBody, $signature, 'sha512')) {
            throw new PaymentsInvalidState('Invalid Paystack webhook signature.');
        }
        /** @var array<string, mixed> $body */
        $body = json_decode($rawBody, true) ?: [];
        $event = (string) ($body['event'] ?? '');
        $data = (array) ($body['data'] ?? []);
        $type = $event === 'charge.success' ? 'payment.succeeded' : ($event === 'refund.processed' ? 'refund.completed' : 'payment.failed');

        return new WebhookPayload(
            eventId: (string) ($data['id'] ?? md5($rawBody)),
            type: $type,
            providerReference: (string) ($data['reference'] ?? ''),
            status: $type === 'payment.succeeded' ? 'succeeded' : 'failed',
            amountMinor: (int) ($data['amount'] ?? 0),
        );
    }
}
