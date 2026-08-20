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

/** Flutterwave adapter. Amounts are major units on the wire; webhooks use a shared secret hash header. */
final class FlutterwaveGateway extends AbstractHttpGateway
{
    public function provider(): PaymentProvider
    {
        return PaymentProvider::Flutterwave;
    }

    public function initialize(GatewayCharge $charge): GatewayResult
    {
        $res = $this->client()->post('/payments', [
            'tx_ref' => $charge->reference,
            'amount' => $charge->amount->minorUnits / 100,
            'currency' => $charge->amount->currency,
            'customer' => ['email' => $charge->customerEmail],
            'meta' => $charge->metadata,
        ]);
        if (! $res->successful()) {
            return $this->result(false, $charge->reference, 'failed');
        }

        return $this->result(true, $charge->reference, 'processing', (string) $res->json('data.link'));
    }

    public function verify(string $providerReference): GatewayResult
    {
        $res = $this->client()->get('/transactions/verify_by_reference', ['tx_ref' => $providerReference]);
        $status = (string) $res->json('data.status');

        return $this->result($status === 'successful', $providerReference, $status === 'successful' ? 'succeeded' : 'failed');
    }

    public function refund(string $providerReference, Money $amount): GatewayResult
    {
        $res = $this->client()->post('/transactions/'.$providerReference.'/refund', ['amount' => $amount->minorUnits / 100]);

        return $this->result($res->successful(), $providerReference.'_rf', $res->successful() ? 'succeeded' : 'failed');
    }

    public function transfer(BankAccount $destination, Money $amount, string $reference): GatewayResult
    {
        return $this->transferResult($reference, fn () => $this->client()->post('/transfers', [
            'account_bank' => $destination->bankCode,
            'account_number' => $destination->accountNumber,
            'amount' => $amount->minorUnits / 100,
            'reference' => $reference,
            'currency' => $amount->currency,
        ]));
    }

    public function parseWebhook(string $rawBody, string $signature): WebhookPayload
    {
        if (! hash_equals((string) ($this->config['webhook_secret'] ?? ''), $signature)) {
            throw new PaymentsInvalidState('Invalid Flutterwave webhook signature.');
        }
        /** @var array<string, mixed> $body */
        $body = json_decode($rawBody, true) ?: [];
        $data = (array) ($body['data'] ?? []);
        $ok = (string) ($data['status'] ?? '') === 'successful';

        return new WebhookPayload(
            eventId: (string) ($data['id'] ?? md5($rawBody)),
            type: $ok ? 'payment.succeeded' : 'payment.failed',
            providerReference: (string) ($data['tx_ref'] ?? ''),
            status: $ok ? 'succeeded' : 'failed',
            amountMinor: (int) round(((float) ($data['amount'] ?? 0)) * 100),
        );
    }
}
