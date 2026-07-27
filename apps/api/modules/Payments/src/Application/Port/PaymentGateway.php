<?php

declare(strict_types=1);

namespace EruoFood\Payments\Application\Port;

use EruoFood\Payments\Application\DTO\GatewayCharge;
use EruoFood\Payments\Application\DTO\GatewayResult;
use EruoFood\Payments\Application\DTO\WebhookPayload;
use EruoFood\Payments\Domain\Enum\PaymentProvider;
use EruoFood\Payments\Domain\ValueObject\BankAccount;
use EruoFood\Shared\Domain\ValueObject\Money;

/**
 * The **Payment Provider Abstraction Layer** — one interface every provider
 * adapter (Paystack, Flutterwave, Moniepoint, Stripe, PayPal, the internal
 * wallet, and the offline mock) implements. Callers depend on this port, never
 * on a specific SDK, so providers are swappable (Strategy pattern) and resolved
 * by the {@see PaymentGatewayFactory}.
 */
interface PaymentGateway
{
    public function provider(): PaymentProvider;

    /** Begin a charge; may return a hosted-checkout authorization URL. */
    public function initialize(GatewayCharge $charge): GatewayResult;

    /** Confirm the final state of a charge by its provider reference. */
    public function verify(string $providerReference): GatewayResult;

    /** Refund (part of) a captured charge. */
    public function refund(string $providerReference, Money $amount): GatewayResult;

    /** Transfer funds to a bank account (vendor/driver payout). */
    public function transfer(BankAccount $destination, Money $amount, string $reference): GatewayResult;

    /**
     * Verify a webhook signature and parse its payload into a normalised event.
     *
     * @throws \EruoFood\Payments\Domain\Exception\PaymentsInvalidState on a bad signature
     */
    public function parseWebhook(string $rawBody, string $signature): WebhookPayload;
}
