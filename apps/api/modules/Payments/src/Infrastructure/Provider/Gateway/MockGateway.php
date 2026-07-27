<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Provider\Gateway;

use EruoFood\Payments\Application\DTO\GatewayCharge;
use EruoFood\Payments\Application\DTO\GatewayResult;
use EruoFood\Payments\Application\DTO\WebhookPayload;
use EruoFood\Payments\Application\Port\PaymentGateway;
use EruoFood\Payments\Domain\Enum\PaymentProvider;
use EruoFood\Payments\Domain\ValueObject\BankAccount;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * A deterministic, offline payment gateway. It "captures" immediately and needs
 * no credentials, so the whole payment flow (initiate → capture → refund →
 * payout → webhook) runs in tests and local development without touching a real
 * provider. This is the default provider when APP_ENV=testing.
 */
final class MockGateway implements PaymentGateway
{
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
        return new GatewayResult(true, 'mock_tr_'.$reference, 'succeeded');
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
