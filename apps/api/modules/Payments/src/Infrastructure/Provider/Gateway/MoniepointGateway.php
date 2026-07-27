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

/** Moniepoint adapter (minor units on the wire; HMAC-SHA256 webhooks). */
final class MoniepointGateway extends AbstractHttpGateway
{
    public function provider(): PaymentProvider
    {
        return PaymentProvider::Moniepoint;
    }

    public function initialize(GatewayCharge $charge): GatewayResult
    {
        $res = $this->client()->post('/v1/transactions/init', [
            'reference' => $charge->reference,
            'amount' => $charge->amount->minorUnits,
            'currency' => $charge->amount->currency,
            'customerEmail' => $charge->customerEmail,
        ]);
        if (! $res->successful()) {
            return $this->result(false, $charge->reference, 'failed');
        }

        return $this->result(true, $charge->reference, 'processing', (string) $res->json('data.checkoutUrl'));
    }

    public function verify(string $providerReference): GatewayResult
    {
        $res = $this->client()->get('/v1/transactions/'.$providerReference);
        $ok = (string) $res->json('data.status') === 'COMPLETED';

        return $this->result($ok, $providerReference, $ok ? 'succeeded' : 'failed');
    }

    public function refund(string $providerReference, Money $amount): GatewayResult
    {
        $res = $this->client()->post('/v1/refunds', ['transactionReference' => $providerReference, 'amount' => $amount->minorUnits]);

        return $this->result($res->successful(), $providerReference.'_rf', $res->successful() ? 'succeeded' : 'failed');
    }

    public function transfer(BankAccount $destination, Money $amount, string $reference): GatewayResult
    {
        $res = $this->client()->post('/v1/disbursements', [
            'reference' => $reference,
            'amount' => $amount->minorUnits,
            'accountNumber' => $destination->accountNumber,
            'bankCode' => $destination->bankCode,
        ]);

        return $this->result($res->successful(), $reference, $res->successful() ? 'processing' : 'failed');
    }

    public function parseWebhook(string $rawBody, string $signature): WebhookPayload
    {
        if (! $this->hmacMatches($rawBody, $signature, 'sha256')) {
            throw new PaymentsInvalidState('Invalid Moniepoint webhook signature.');
        }
        /** @var array<string, mixed> $body */
        $body = json_decode($rawBody, true) ?: [];
        $data = (array) ($body['data'] ?? []);
        $ok = (string) ($data['status'] ?? '') === 'COMPLETED';

        return new WebhookPayload(
            eventId: (string) ($body['id'] ?? md5($rawBody)),
            type: $ok ? 'payment.succeeded' : 'payment.failed',
            providerReference: (string) ($data['reference'] ?? ''),
            status: $ok ? 'succeeded' : 'failed',
            amountMinor: (int) ($data['amount'] ?? 0),
        );
    }
}
