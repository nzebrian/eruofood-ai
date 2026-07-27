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
 * PayPal adapter — architecture-ready (disabled by default). Uses the Orders v2
 * API; amounts are major-unit decimal strings on the wire.
 */
final class PaypalGateway extends AbstractHttpGateway
{
    public function provider(): PaymentProvider
    {
        return PaymentProvider::Paypal;
    }

    public function initialize(GatewayCharge $charge): GatewayResult
    {
        $res = $this->client()->post('/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $charge->reference,
                'amount' => [
                    'currency_code' => $charge->amount->currency,
                    'value' => number_format($charge->amount->minorUnits / 100, 2, '.', ''),
                ],
            ]],
        ]);
        if (! $res->successful()) {
            return $this->result(false, $charge->reference, 'failed');
        }

        return $this->result(true, (string) $res->json('id'), 'processing');
    }

    public function verify(string $providerReference): GatewayResult
    {
        $res = $this->client()->get('/v2/checkout/orders/'.$providerReference);
        $ok = (string) $res->json('status') === 'COMPLETED';

        return $this->result($ok, $providerReference, $ok ? 'succeeded' : 'failed');
    }

    public function refund(string $providerReference, Money $amount): GatewayResult
    {
        $res = $this->client()->post('/v2/payments/captures/'.$providerReference.'/refund', [
            'amount' => ['value' => number_format($amount->minorUnits / 100, 2, '.', ''), 'currency_code' => $amount->currency],
        ]);

        return $this->result($res->successful(), $providerReference.'_rf', $res->successful() ? 'succeeded' : 'failed');
    }

    public function transfer(BankAccount $destination, Money $amount, string $reference): GatewayResult
    {
        $res = $this->client()->post('/v1/payments/payouts', [
            'sender_batch_header' => ['sender_batch_id' => $reference],
            'items' => [[
                'amount' => ['value' => number_format($amount->minorUnits / 100, 2, '.', ''), 'currency' => $amount->currency],
                'receiver' => $destination->accountNumber,
            ]],
        ]);

        return $this->result($res->successful(), $reference, $res->successful() ? 'processing' : 'failed');
    }

    public function parseWebhook(string $rawBody, string $signature): WebhookPayload
    {
        if (! $this->hmacMatches($rawBody, $signature, 'sha256')) {
            throw new PaymentsInvalidState('Invalid PayPal webhook signature.');
        }
        /** @var array<string, mixed> $body */
        $body = json_decode($rawBody, true) ?: [];
        $type = (string) ($body['event_type'] ?? '');
        $resource = (array) ($body['resource'] ?? []);
        $ok = $type === 'PAYMENT.CAPTURE.COMPLETED';

        return new WebhookPayload(
            eventId: (string) ($body['id'] ?? md5($rawBody)),
            type: $ok ? 'payment.succeeded' : 'payment.failed',
            providerReference: (string) ($resource['id'] ?? ''),
            status: $ok ? 'succeeded' : 'failed',
            amountMinor: (int) round(((float) ($resource['amount']['value'] ?? 0)) * 100),
        );
    }
}
