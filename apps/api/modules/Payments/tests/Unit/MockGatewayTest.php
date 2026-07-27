<?php

declare(strict_types=1);

use EruoFood\Payments\Application\DTO\GatewayCharge;
use EruoFood\Payments\Domain\Enum\PaymentMethodType;
use EruoFood\Payments\Domain\ValueObject\BankAccount;
use EruoFood\Payments\Infrastructure\Provider\Gateway\MockGateway;
use EruoFood\Shared\Domain\ValueObject\Money;

it('captures, verifies, refunds, transfers and parses webhooks offline', function (): void {
    $gw = new MockGateway();
    $init = $gw->initialize(new GatewayCharge('PMT-1', new Money(500000, 'NGN'), 'a@b.co', PaymentMethodType::Card));
    expect($init->success)->toBeTrue()->and($init->status)->toBe('succeeded');

    expect($gw->verify($init->providerReference)->status)->toBe('succeeded')
        ->and($gw->refund($init->providerReference, new Money(100000, 'NGN'))->success)->toBeTrue()
        ->and($gw->transfer(new BankAccount('A', '0001', '058'), new Money(100000, 'NGN'), 'PO-1')->success)->toBeTrue();

    $webhook = $gw->parseWebhook(json_encode(['event_id' => 'evt1', 'type' => 'payment.succeeded', 'reference' => 'mock_PMT-1', 'status' => 'succeeded']), '');
    expect($webhook->eventId)->toBe('evt1')->and($webhook->type)->toBe('payment.succeeded');
});
