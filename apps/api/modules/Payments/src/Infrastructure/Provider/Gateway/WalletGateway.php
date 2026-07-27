<?php

declare(strict_types=1);

namespace EruoFood\Payments\Infrastructure\Provider\Gateway;

use EruoFood\Payments\Application\DTO\GatewayCharge;
use EruoFood\Payments\Application\DTO\GatewayResult;
use EruoFood\Payments\Application\DTO\WebhookPayload;
use EruoFood\Payments\Application\Port\PaymentGateway;
use EruoFood\Payments\Domain\Enum\PaymentProvider;
use EruoFood\Payments\Domain\Exception\PaymentsInvalidState;
use EruoFood\Payments\Domain\ValueObject\BankAccount;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * The internal "provider" for wallet-funded payments and wallet-side refunds.
 * There is no external call — the WalletService moves the balance — so this
 * gateway simply confirms success against the local reference.
 */
final class WalletGateway implements PaymentGateway
{
    public function provider(): PaymentProvider
    {
        return PaymentProvider::Wallet;
    }

    public function initialize(GatewayCharge $charge): GatewayResult
    {
        return new GatewayResult(true, 'wallet_'.$charge->reference, 'succeeded');
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
        return new GatewayResult(true, 'wallet_tr_'.$reference, 'succeeded');
    }

    public function parseWebhook(string $rawBody, string $signature): WebhookPayload
    {
        throw new PaymentsInvalidState('The wallet provider does not receive webhooks.');
    }
}
